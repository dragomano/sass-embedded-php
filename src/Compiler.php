<?php

declare(strict_types=1);

namespace Bugo\Sass;

use Symfony\Component\Process\Process;

class Compiler implements CompilerInterface
{
    use HandlesSourceMaps;

    /**
     * The `sourceMappingURL` comment that `--embed-source-map` appends. Dart
     * Sass percent-encodes the JSON rather than base64-encoding it.
     */
    private const EMBEDDED_SOURCE_MAP = '~\s*/\*# sourceMappingURL=data:application/json;charset=utf-8,(\S+) \*/~';

    public function __construct(protected Options $options = new Options()) {}

    public function setOptions(Options $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    public function compileString(string $source, ?Options $options = null): string
    {
        if (trim($source) === '') {
            return '';
        }

        $options = $this->resolveOptions($options);

        return $this->compileSource($source, $options);
    }

    public function compileFile(string $path, ?Options $options = null): string
    {
        if (! file_exists($path)) {
            throw new Exception("File not found: $path");
        }

        $options = $this->resolveOptions($options);

        return $this->compileFileNative($path, $options);
    }

    public function compileFileAndSave(string $inputPath, string $outputPath, ?Options $options = null): bool
    {
        if (! file_exists($inputPath)) {
            throw new Exception("Source file not found: $inputPath");
        }

        $inputMtime  = filemtime($inputPath);
        $outputMtime = file_exists($outputPath) ? filemtime($outputPath) : 0;

        if ($inputMtime > $outputMtime) {
            $css = $this->compileFile($inputPath, $options);

            file_put_contents($outputPath, $css);

            return true;
        }

        return false;
    }

    protected function compileFileNative(string $filePath, array $options): string
    {
        $args = [...$this->buildSassArgs($options), $filePath];

        $process = $this->createProcess([...$this->getSassCommand(), ...$args]);
        $process->run();

        $out = trim($process->getOutput());
        $err = trim($process->getErrorOutput());

        if (! $process->isSuccessful()) {
            throw new Exception('Sass compilation error: ' . ($err ?: $out));
        }

        $css = $out ?: $err;

        return $this->applySourceMap($css, $options, $filePath);
    }

    protected function resolveOptions(?Options $options = null): array
    {
        return array_filter(
            (array) $this->options->withOverrides($options),
            static fn($value): bool => $value !== null
        );
    }

    protected function compileSource(string $source, array $options): string
    {
        $args = $this->buildSassArgs($options);

        $process = $this->createProcess([...$this->getSassCommand(), ...$args, '--stdin']);
        $process->setInput($source);
        $process->run();

        $out = trim($process->getOutput());
        $err = trim($process->getErrorOutput());

        if (! $process->isSuccessful()) {
            throw new Exception('Sass compilation error: ' . ($err ?: $out ?: 'unknown error'));
        }

        return $this->applySourceMap(
            $out,
            $options,
            $options['url'] ?? $options['sourceFile'] ?? ''
        );
    }

    /**
     * Re-emits the map that `--embed-source-map` returned inside the CSS.
     *
     * Writing to stdout is the only mode this bridge uses, and there the CLI can
     * hand a map back solely by embedding it. So the data URI is unpacked here
     * and the map is then placed wherever `sourceMapPath` asks for.
     */
    protected function applySourceMap(string $css, array $options, string $name): string
    {
        $this->sourceMap = null;

        if (! self::wantsSourceMap($options['sourceMapPath'] ?? null)) {
            return $css;
        }

        if (preg_match(self::EMBEDDED_SOURCE_MAP, $css, $matches) !== 1) {
            return $css;
        }

        $sourceMap = json_decode(rawurldecode($matches[1]), true);

        if (is_array($sourceMap) && isset($options['url'])) {
            $sourceMap['sourceRoot'] = '';
            $sourceMap['sources']    = [$options['url']];
        }

        return $this->emitSourceMap(
            (string) preg_replace(self::EMBEDDED_SOURCE_MAP, '', $css),
            (string) json_encode($sourceMap),
            $options['sourceMapPath'],
            ($options['style'] ?? null) === 'compressed',
            $name,
        );
    }

    protected function buildSassArgs(array $opts): array
    {
        $args = [];

        if (($opts['syntax'] ?? null) === 'indented') {
            $args[] = '--indented';
        }

        if ($opts['style'] ?? null) {
            $args[] = '--style=' . $opts['style'];
        }

        if (self::wantsSourceMap($opts['sourceMapPath'] ?? null)) {
            $args[] = '--embed-source-map';

            if ($opts['includeSources'] ?? false) {
                $args[] = '--embed-sources';
            }
        }

        if ($opts['loadPaths'] ?? []) {
            foreach ($opts['loadPaths'] as $loadPath) {
                $args[] = '--load-path=' . $loadPath;
            }
        }

        if ($opts['quietDeps'] ?? false) {
            $args[] = '--quiet-deps';
        }

        if ($opts['silenceDeprecations'] ?? []) {
            foreach ($opts['silenceDeprecations'] as $deprecation) {
                $args[] = '--silence-deprecation=' . $deprecation;
            }
        }

        return $args;
    }

    protected function createProcess(array $command): Process
    {
        return new Process($command);
    }

    protected function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    protected function getSassCommand(): array
    {
        $binDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'bin';

        if ($this->isWindows()) {
            return [
                $binDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'dart.exe',
                $binDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'sass.snapshot',
            ];
        }

        return [$binDir . DIRECTORY_SEPARATOR . 'sass'];
    }
}
