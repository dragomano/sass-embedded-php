<?php

declare(strict_types=1);

namespace Bugo\Sass;

use Symfony\Component\Process\Process;

use function array_filter;
use function array_merge;
use function base64_encode;
use function dirname;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function is_array;
use function json_decode;
use function json_encode;
use function preg_replace_callback;
use function trim;
use function urldecode;

class Compiler implements CompilerInterface
{
    public function __construct(protected Options $options = new Options()) {}

    public function setOptions(Options $options): static
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

        $options = array_merge($this->resolveOptions(), $this->resolveOptions($options));

        return $this->compileSource($source, $options);
    }

    public function compileFile(string $filePath, ?Options $options = null): string
    {
        if (! file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }

        $options = array_merge($this->resolveOptions(), $this->resolveOptions($options));

        return $this->compileFileNative($filePath, $options);
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

        return $this->rewriteSourceMap($css, $options);
    }

    protected function resolveOptions(?Options $options = null): array
    {
        return array_filter((array) ($options ?? $this->options), static fn($value): bool => $value !== null);
    }

    protected function compileSource(string $source, array $options): string
    {
        $args = $this->buildSassArgs($options);

        $process = $this->createProcess([...$this->getSassCommand(), ...$args, '--stdin']);
        $process->setInput($source);
        $process->run();

        $out = trim($process->getOutput());
        $err = trim($process->getErrorOutput());

        if ($err !== '' && $process->isSuccessful() === false) {
            throw new Exception('Sass compilation error: ' . $err);
        }

        if ($out === '') {
            throw new Exception('Sass process failed: ' . ($err ?: 'unknown error'));
        }

        return $this->rewriteSourceMap($out, $options);
    }

    protected function rewriteSourceMap(string $css, array $options): string
    {
        return preg_replace_callback(
            '#sourceMappingURL=data:application/json;charset=utf-8,([^ ]+)#',
            static function (array $matches) use ($options): string {
                $sourceMap = json_decode(urldecode($matches[1]), true);

                if (is_array($sourceMap) && isset($options['url'])) {
                    $sourceMap['sourceRoot'] = '';
                    $sourceMap['sources']    = [$options['url']];
                }

                return 'sourceMappingURL=data:application/json;base64,' . base64_encode((string) json_encode($sourceMap));
            },
            $css
        ) ?? $css;
    }

    protected function buildSassArgs(array $opts): array
    {
        $args = [];

        if ($opts['style'] ?? null) {
            $args[] = '--style=' . $opts['style'];
        }

        if (($opts['sourceMapPath'] ?? null) === 'inline') {
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
