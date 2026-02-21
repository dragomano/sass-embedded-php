<?php declare(strict_types=1);

namespace Bugo\Sass;

use Generator;
use Symfony\Component\Process\Process;

use function array_merge;
use function base64_encode;
use function basename;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filter_var;
use function implode;
use function is_dir;
use function json_decode;
use function json_encode;
use function parse_url;
use function preg_match;
use function pathinfo;
use function realpath;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;
use const FILTER_VALIDATE_URL;

class Compiler implements CompilerInterface, PersistentCompilerInterface
{
    protected array $options = [];

    protected static ?Process $cachedProcess = null;

    protected static ?array $cachedCommand = null;

    protected bool $persistentMode = false;

    protected ?Process $persistentProcess = null;

    private const STREAM_THRESHOLD = 1024 * 1024;

    private const DEFAULT_FILENAME = 'style';

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

    public function compileFileAndSave(string $inputPath, string $outputPath, array $options = []): bool
    {
        if (! file_exists($inputPath)) {
            throw new Exception("Source file not found: $inputPath");
        }

        $inputMtime = $this->getFileMtime($inputPath);
        $outputMtime = file_exists($outputPath) ? $this->getFileMtime($outputPath) : 0;

        if ($inputMtime > $outputMtime) {
            if (! empty($options['sourceMap']) && empty($options['sourceMapPath'])) {
                $options['sourceMapPath'] = $outputPath;
            }

            $css = $this->compileFile($inputPath, $options);
            file_put_contents($outputPath, $css);

            return true;
        }

        return false;
    }

    public function compileStringAsGenerator(string $source, array $options = []): Generator
    {
        if (trim($source) === '') {
            yield '';
            return;
        }

        $options = array_merge($this->options, $options);

        if (strlen($source) > self::STREAM_THRESHOLD) {
            $options['streamResult'] = true;
        }

        $payload = $this->preparePayload($source, $options);
        $result = $this->runCompile($payload);

        if (isset($result['isStreamed']) && $result['isStreamed']) {
            foreach ($result['chunks'] as $chunk) {
                yield $chunk;
            }
        } else {
            yield $result['css'] ?? '';
        }

        if (! empty($result['sourceMap'])) {
            yield $this->processSourceMap($result['sourceMap'], $options);
        }
    }

    public function compileInPersistentMode(string $source, array $options = []): string
    {
        if (trim($source) === '') {
            return '';
        }

        $options = array_merge($this->options, $options);

        return $this->compileSource($source, $options, true);
    }

    public function enablePersistentMode(): static
    {
        $this->persistentMode = true;

        return $this;
    }

    public function disablePersistentMode(): void
    {
        if ($this->persistentProcess !== null && $this->persistentProcess->isRunning()) {
            $this->persistentProcess->setInput(json_encode(['exit' => true]) . "\n");
            $this->persistentProcess->run();
            $this->persistentProcess->stop();
        }

        $this->persistentProcess = null;
        $this->persistentMode = false;
    }

    protected function getFileMtime(string $path): int
    {
        return filemtime($path);
    }

    protected function compileSource(string $source, array $options, bool $persistent = false): string
    {
        $payload = $this->preparePayload($source, $options);
        $data = $persistent ? $this->runCompilePersistent($payload) : $this->runCompile($payload);

        return $this->buildCssWithSourceMap($data, $options);
    }

    protected function preparePayload(string $source, array $options): array
    {
        return [
            'source'  => $source,
            'options' => $options,
            'url'     => $options['url'] ?? null,
        ];
    }

    protected function buildCssWithSourceMap(array $data, array $options): string
    {
        if (isset($data['isStreamed']) && $data['isStreamed'] && isset($data['chunks'])) {
            $css = implode('', $data['chunks']);
        } else {
            $css = $data['css'] ?? '';
        }

        $sourceMap = $data['sourceMap'] ?? null;

        if (isset($data['sourceMapIsStreamed']) && $data['sourceMapIsStreamed'] && isset($data['sourceMapChunks'])) {
            $sourceMap = implode('', $data['sourceMapChunks']);
            $sourceMap = json_decode($sourceMap, true);
        }

        if (! empty($sourceMap)) {
            $css .= $this->processSourceMap($sourceMap, $options);
        }

        return $css;
    }

    protected function runCompilePersistent(array $payload): array
    {
        $process = $this->getOrCreatePersistentProcess();
        $process->setInput(json_encode($payload) . "\n");
        $process->run();

        return $this->parseProcessOutput($process, 'persistent');
    }

    protected function runCompile(array $payload): array
    {
        $cmd = [$this->nodePath, $this->bridgePath, '--stdin'];
        $process = $this->getOrCreateProcess($cmd);
        $process->setInput(json_encode($payload));
        $process->run();

        return $this->parseProcessOutput($process, 'standard');
    }

    protected function parseProcessOutput(Process $process, string $mode): array
    {
        $out = trim($process->getOutput());
        if ($out === '') {
            $err = trim($process->getErrorOutput());
            $errorType = $mode === 'persistent' ? 'persistent process' : 'process';
            throw new Exception("Sass $errorType failed: " . ($err ?: 'unknown error'));
        }

        $data = json_decode($out, true);
        if ($data === null) {
            $errorType = $mode === 'persistent' ? 'persistent bridge' : 'bridge';
            throw new Exception("Invalid response from sass $errorType");
        }

        if (! empty($data['error'])) {
            throw new Exception('Sass parsing error: ' . $data['error']);
        }

        return $data;
    }

    protected function processSourceMap(array $sourceMap, array $options): string
    {
        if (empty($options['sourceMapPath'])) {
            return $this->inlineSourceMap($sourceMap);
        }

        return $this->fileSourceMap($sourceMap, $options);
    }

    protected function inlineSourceMap(array $sourceMap): string
    {
        $mapData = json_encode($sourceMap);
        $encodedMap = base64_encode($mapData);

        return "\n/*# sourceMappingURL=data:application/json;base64," . $encodedMap . " */";
    }

    protected function fileSourceMap(array $sourceMap, array $options): string
    {
        $mapFile = (string) $options['sourceMapPath'];

        $isUrl = filter_var($mapFile, FILTER_VALIDATE_URL) !== false && preg_match('/^https?:/', $mapFile);
        if ($isUrl) {
            return "\n/*# sourceMappingURL=" . $mapFile . " */";
        }

        if (is_dir($mapFile)) {
            $sourceFilename = $this->getSourceFilenameFromUrl($options['url'] ?? '');
            $mapFile .= DIRECTORY_SEPARATOR . $sourceFilename . '.map';
        } elseif (strtolower(substr($mapFile, -4)) !== '.map') {
            $mapFile .= '.map';
        }

        file_put_contents($mapFile, json_encode($sourceMap));
        $sourceMappingUrl = basename($mapFile);

        return "\n/*# sourceMappingURL=" . $sourceMappingUrl . " */";
    }

    protected function getSourceFilenameFromUrl(string $url): string
    {
        if ($url === '') {
            return self::DEFAULT_FILENAME;
        }

        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $basename = basename($path);
        $info = pathinfo($basename);

        return $info['filename'] ?: self::DEFAULT_FILENAME;
    }

    protected function readFile(string $path): string|false
    {
        return file_get_contents($path);
    }

    protected function getOrCreateProcess(array $command): Process
    {
        if (self::$cachedProcess !== null && self::$cachedCommand === $command) {
            if (self::$cachedProcess->isRunning()) {
                return self::$cachedProcess;
            }

            self::$cachedProcess = null;
            self::$cachedCommand = null;
        }

        $process = $this->createProcess($command);

        self::$cachedProcess = $process;
        self::$cachedCommand = $command;

        return $process;
    }

    protected function createProcess(array $command): Process
    {
        return new Process($command);
    }

    protected function getOrCreatePersistentProcess(): Process
    {
        if ($this->persistentProcess !== null && $this->persistentProcess->isRunning()) {
            return $this->persistentProcess;
        }

        $cmd = [$this->nodePath, $this->bridgePath, '--persistent'];
        $this->persistentProcess = $this->createProcess($cmd);
        $this->persistentProcess->start();

        return $this->persistentProcess;
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

        throw new Exception(implode('', [
            'Node.js not found. ',
            "Please install Node.js >= 18 and make sure it's in PATH, ",
            'or pass its full path to your Compiler constructor.',
        ]));
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
