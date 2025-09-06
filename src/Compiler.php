<?php declare(strict_types=1);

namespace Bugo\Sass;

use Symfony\Component\Process\Process;

use function array_merge;
use function basename;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function realpath;
use function strtoupper;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function trim;

class Compiler implements CompilerInterface
{
    protected array $options = [];

    public function __construct(protected ?string $bridgePath = null, protected ?string $nodePath = null)
    {
        $this->bridgePath = $bridgePath ?? __DIR__ . '/../bin/bridge.js';
        $this->nodePath = $nodePath ?? $this->findNode();
        $this->checkEnvironment();
    }

    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function compileString(string $source, array $options = []): string
    {
        if (trim($source) === '') {
            return '';
        }

        $options = array_merge($this->options, $options);

        if (! isset($merged['url'])) {
            $options['url'] = 'file://' . getcwd() . '/string.scss';
        }

        return $this->compileSource($source, $options);
    }

    public function compileFile(string $filePath, array $options = []): string
    {
        if (! file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }

        $content = $this->readFile($filePath);
        if ($content === false) {
            throw new Exception("Unable to read file: $filePath");
        }

        if (trim($content) === '') {
            return '';
        }

        $options = array_merge($this->options, $options);

        if (! isset($options['url'])) {
            $options['url'] = 'file://' . realpath($filePath);
        }

        return $this->compileSource($content, $options);
    }

    protected function compileSource(string $source, array $options): string
    {
        $payload = [
            'source' => $source,
            'options' => $options,
            'url' => $options['url'],
        ];

        return $this->runCompile($payload);
    }

    protected function runCompile(array $payload): string
    {
        $cmd = [$this->nodePath, $this->bridgePath, '--stdin'];

        $process = $this->createProcess($cmd);
        $process->setInput(json_encode($payload));
        $process->run();

        $out = trim($process->getOutput());
        if ($out === '') {
            $err = trim($process->getErrorOutput());
            throw new Exception('Sass process failed: ' . ($err ?: 'unknown error'));
        }

        $data = json_decode($out, true);
        if (! is_array($data)) {
            throw new Exception('Invalid response from sass bridge');
        }

        if (! empty($data['error'])) {
            throw new Exception('Sass parsing error: ' . $data['error']);
        }

        $css = $data['css'] ?? '';

        if (! empty($payload['options']['sourceMap']) && ! empty($data['sourceMap'])) {
            $mapFile = tempnam(sys_get_temp_dir(), 'sass_') . '.map';
            file_put_contents($mapFile, json_encode($data['sourceMap']));
            $css .= "\n/*# sourceMappingURL=" . basename($mapFile) . " */";
        }

        return $css;
    }

    protected function readFile(string $path): string|false
    {
        return file_get_contents($path);
    }

    protected function createProcess(array $command): Process
    {
        return new Process($command);
    }

    protected function findNode(): string
    {
        $candidates = ['node'];
        if ($this->isWindows()) {
            $candidates[] = 'C:\\Program Files\\nodejs\\node.exe';
            $candidates[] = 'C:\\Program Files (x86)\\nodejs\\node.exe';
        } else {
            $candidates[] = '/usr/local/bin/node';
            $candidates[] = '/usr/bin/node';
            $candidates[] = '/opt/homebrew/bin/node';
        }

        foreach ($candidates as $node) {
            $process = $this->createProcess([$node, '--version']);
            $process->run();
            if ($process->isSuccessful()) {
                return $node;
            }
        }

        throw new Exception(
            "Node.js not found. Please install Node.js >= 18 and make sure it's in PATH, " .
            "or pass its full path to your Compiler constructor."
        );
    }

    protected function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    protected function checkEnvironment(): void
    {
        if (! file_exists($this->bridgePath)) {
            throw new Exception("bridge.js not found at $this->bridgePath");
        }

        $nodeModules = $this->getPackageRoot() . '/node_modules/sass-embedded';
        if (! is_dir($nodeModules)) {
            throw new Exception("sass-embedded not found. Run `npm install` in {$this->getPackageRoot()}.");
        }
    }

    protected function getPackageRoot(): string
    {
        return realpath(__DIR__ . '/../');
    }
}
