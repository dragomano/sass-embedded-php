<?php

declare(strict_types=1);

namespace Bugo\Sass;

use Closure;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

final class EmbeddedCompiler implements CompilerInterface
{
    use HandlesSourceMaps;

    private const PROTOCOL_MAJOR = 3;

    private const MAX_VARINT_BYTES = 10;

    private const MAX_PACKET_LENGTH = 268_435_456;

    private const POLL_INTERVAL_MICROS = 1_000;

    private const STDERR_TAIL_BYTES = 8192;

    private const CLOSE_TIMEOUT = 0.5;

    private ?Process $process = null;

    private ?InputStream $input = null;

    private int $nextId = 1;

    private string $stderr = '';

    private string $readBuffer = '';

    private int $maxRetries = 1;

    private bool $ownsProcess = false;

    /** @var list<LogEvent> */
    private array $logs = [];

    private ?Closure $logHandler = null;

    public function __construct(
        private readonly float $timeout = 15.0,
        private Options $options = new Options(),
        private readonly ?Closure $processFactory = null,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function setOptions(Options $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    /** @return list<LogEvent> */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * @param (callable(LogEvent): void)|null $handler
     * @return EmbeddedCompiler
     */
    public function setLogHandler(?callable $handler): self
    {
        $this->logHandler = $handler === null ? null : $handler(...);

        return $this;
    }

    public function compileString(string $source, ?Options $options = null): string
    {
        if (trim($source) === '') {
            return '';
        }

        $options = $this->options->withOverrides($options);

        $url   = self::stringUrl($options);
        $input = self::field(1, $source);

        if ($url !== '') {
            $input .= self::field(2, $url);
        }

        if ($options->syntax === 'indented') {
            $input .= self::scalar(3, 1);
        }

        return $this->compile(self::field(2, $input), $options, $url);
    }

    public function compileFile(string $path, ?Options $options = null): string
    {
        if (! file_exists($path)) {
            throw new Exception("File not found: $path");
        }

        $options = $this->options->withOverrides($options);

        return $this->compile(self::field(3, $path), $options, $path);
    }

    public function compileFileAndSave(string $inputPath, string $outputPath, ?Options $options = null): bool
    {
        if (! file_exists($inputPath)) {
            throw new Exception("Source file not found: $inputPath");
        }

        if (filemtime($inputPath) <= (file_exists($outputPath) ? filemtime($outputPath) : 0)) {
            return false;
        }

        file_put_contents($outputPath, $this->compileFile($inputPath, $options));

        return true;
    }

    public function close(): void
    {
        $process = $this->process;
        $input   = $this->input;

        $this->process     = null;
        $this->input       = null;
        $this->stderr      = '';
        $this->readBuffer  = '';
        $this->ownsProcess = false;

        if ($input instanceof InputStream) {
            try {
                $input->close();
            } catch (Throwable) {
                // Already closed, or the process is already gone. Fine either way.
            }
        }

        if (! $process instanceof Process) {
            return;
        }

        if ($process->isRunning()) {
            $process->stop(self::CLOSE_TIMEOUT);
        }
    }

    private function compile(string $input, Options $options, string $name): string
    {
        $attempt = 0;

        while (true) {
            $this->logs = [];

            $this->sourceMap = null;

            try {
                return $this->compileOnce($input, $options, $name);
            } catch (TransportException $exception) {
                $shouldRetry = $this->ownsProcess && $attempt++ < $this->maxRetries;

                $this->close();

                if (! $shouldRetry) {
                    throw $exception;
                }
            } catch (ProtocolException $exception) {
                $this->close();

                throw $exception;
            }
        }
    }

    private function compileOnce(string $input, Options $options, string $name): string
    {
        $this->start();

        $deadline = $this->deadline();
        $id       = $this->nextId++;
        $request  = $input . $this->compileOptions($options);

        $this->send($id, self::field(2, $request));

        while (true) {
            [$responseId, $message] = $this->readMessage($deadline);

            $outbound = self::fields($message);

            if (isset($outbound[1][0])) {
                $error = self::fields($outbound[1][0]);

                throw new ProtocolException(
                    $error[3][0] ?? 'The Dart Sass embedded compiler rejected the request.',
                );
            }

            if (isset($outbound[3][0])) {
                $this->log(self::fields($outbound[3][0]));

                continue;
            }

            if (! isset($outbound[2][0])) {
                throw new ProtocolException(
                    'The Dart Sass embedded compiler sent an unsupported message.',
                );
            }

            if ($responseId !== $id) {
                throw new ProtocolException(
                    'The Dart Sass embedded compiler returned a response for an unexpected compilation.',
                );
            }

            $response = self::fields($outbound[2][0]);

            if (isset($response[2][0])) {
                $success = self::fields($response[2][0]);

                return $this->emitSourceMap(
                    $success[1][0] ?? '',
                    $success[2][0] ?? '',
                    $options->sourceMapPath,
                    $options->style === 'compressed',
                    $name,
                );
            }

            $failure = self::fields($response[3][0] ?? '');

            throw new Exception($failure[4][0] ?? $failure[1][0] ?? 'Sass embedded compilation failed.');
        }
    }

    private function log(array $event): void
    {
        $type = isset($event[2][0]) ? self::integer($event[2][0]) : LogType::Warning->value;

        $logEvent = new LogEvent(
            LogType::tryFrom($type) ?? LogType::Warning,
            $event[3][0] ?? '',
            $event[6][0] ?? '',
            $event[7][0] ?? null,
            $event[5][0] ?? '',
        );

        $this->logs[] = $logEvent;

        if ($this->logHandler instanceof Closure) {
            ($this->logHandler)($logEvent);
        }
    }

    private static function stringUrl(Options $options): string
    {
        $url = $options->url ?? $options->sourceFile ?? '';

        if ($url === '') {
            return '';
        }

        return self::hasUrlScheme($url) ? $url : self::fileUrl($url);
    }

    private static function fileUrl(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (preg_match('#^(?:[a-zA-Z]:/|/)#', $path) !== 1) {
            $path = str_replace('\\', '/', (string) getcwd()) . '/' . $path;
        }

        $segments = explode('/', ltrim($path, '/'));
        $drive    = '';

        if (preg_match('/^[a-zA-Z]:$/', $segments[0]) === 1) {
            $drive = array_shift($segments) . '/';
        }

        return 'file:///' . $drive . implode('/', array_map(rawurlencode(...), $segments));
    }

    private function compileOptions(Options $options): string
    {
        $request = self::scalar(9, 1) . self::scalar(13, 1);

        if ($options->style === 'compressed') {
            $request .= self::scalar(4, 1);
        }

        if (self::wantsSourceMap($options->sourceMapPath)) {
            $request .= self::scalar(5, 1);
            if ($options->includeSources) {
                $request .= self::scalar(12, 1);
            }
        }

        foreach ($options->loadPaths ?? [] as $path) {
            $request .= self::field(6, self::field(1, $path));
        }

        if ($options->quietDeps) {
            $request .= self::scalar(11, 1);
        }

        if ($options->verbose) {
            $request .= self::scalar(10, 1);
        }

        foreach ($options->silenceDeprecations ?? [] as $deprecation) {
            $request .= self::field(16, $deprecation);
        }

        return $request;
    }

    private function start(): void
    {
        if ($this->processIsRunning()) {
            return;
        }

        if ($this->process instanceof Process) {
            $this->close();
        }

        $root = dirname(__DIR__);

        $this->input = new InputStream();

        try {
            $command = self::command($root, PHP_OS_FAMILY);
            $process = $this->processFactory instanceof Closure
                ? ($this->processFactory)($command)
                : new Process($command);

            if (! $process instanceof Process) {
                throw new ProtocolException('The process factory did not return a Symfony Process instance.');
            }

            $this->process = $process;
            $this->process->setInput($this->input);
            $this->process->setTimeout(null);
            $this->process->start();
        } catch (Throwable $throwable) {
            $this->close();

            throw new ProtocolException(
                'Unable to start the Dart Sass embedded compiler: ' . $throwable->getMessage(),
                previous: $throwable,
            );
        }

        $this->ownsProcess = true;
        $this->stderr      = '';
        $this->readBuffer  = '';

        $this->handshake();
    }

    private function handshake(): void
    {
        $deadline = $this->deadline();

        $this->send(0, self::field(7, ''));

        [, $message] = $this->readMessage($deadline);

        $outbound = self::fields($message);

        if (isset($outbound[1][0])) {
            $error = self::fields($outbound[1][0]);

            throw new ProtocolException(
                $error[3][0] ?? 'The Dart Sass embedded compiler rejected the version request.',
            );
        }

        if (! isset($outbound[8][0])) {
            throw new ProtocolException(
                'The Dart Sass embedded compiler did not respond to the version request.',
            );
        }

        $version = self::fields($outbound[8][0])[1][0] ?? '';

        if ($version === '') {
            throw new ProtocolException(
                'The Dart Sass embedded compiler did not report its protocol version.',
            );
        }

        if ((int) explode('.', $version)[0] !== self::PROTOCOL_MAJOR) {
            throw new ProtocolException(
                sprintf(
                    'Unsupported Sass embedded protocol version %s: this package speaks %d.x. Install a matching Dart Sass release.',
                    $version,
                    self::PROTOCOL_MAJOR,
                ),
            );
        }
    }

    private function send(int $compilationId, string $message): void
    {
        $packet = self::varint($compilationId) . $message;

        $this->write(self::varint(strlen($packet)) . $packet);
    }

    private static function command(string $root, string $osFamily): array
    {
        return (
            $osFamily === 'Windows'
                ? [$root . '/bin/src/dart.exe', $root . '/bin/src/sass.snapshot', '--embedded']
                : [$root . '/bin/sass', '--embedded']
        );
    }

    private function write(string $data): void
    {
        if (! $this->ownsProcess || ! $this->processIsRunning()) {
            throw new TransportException(
                $this->diagnostic('The Dart Sass embedded compiler stopped unexpectedly before writing.'),
            );
        }

        try {
            $this->input->write($data);
        } catch (Throwable $throwable) {
            throw new TransportException(
                $this->diagnostic('Unable to write to the Dart Sass embedded compiler: ' . $throwable->getMessage()),
            );
        }

        $this->drainStderr();

        if ($this->ownsProcess && ! $this->processIsRunning()) {
            throw new TransportException(
                $this->diagnostic('The Dart Sass embedded compiler stopped unexpectedly after writing.'),
            );
        }
    }

    private function readMessage(float $deadline): array
    {
        $length = $this->readVarint($deadline);

        if ($length < 1) {
            throw new ProtocolException('The Dart Sass embedded compiler sent an empty packet.');
        }

        if ($length > self::MAX_PACKET_LENGTH) {
            throw new ProtocolException(
                sprintf('The Dart Sass embedded compiler sent an oversized packet (%d bytes).', $length),
            );
        }

        $this->fillReadBuffer($length, $deadline);

        $packet = substr($this->readBuffer, 0, $length);

        $this->readBuffer = substr($this->readBuffer, $length);

        $offset = 0;

        $compilationId = self::decodeVarint($packet, $offset);

        return [$compilationId, substr($packet, $offset)];
    }

    private function readVarint(float $deadline): int
    {
        $value = 0;
        $shift = 0;

        for ($index = 0; $index < self::MAX_VARINT_BYTES; $index++) {
            $this->fillReadBuffer($index + 1, $deadline);

            $byte = ord($this->readBuffer[$index]);

            $payload = $byte & 0x7f;

            if ($shift >= 63 && $payload !== 0) {
                throw new ProtocolException('The Dart Sass embedded compiler sent an overflowing varint.');
            }

            $value |= $payload << $shift;

            if (($byte & 0x80) === 0) {
                $this->readBuffer = substr($this->readBuffer, $index + 1);

                return $value;
            }

            $shift += 7;
        }

        throw new ProtocolException('The Dart Sass embedded compiler sent an overlong varint.');
    }

    private function fillReadBuffer(int $length, float $deadline): void
    {
        while (strlen($this->readBuffer) < $length) {
            $chunk = $this->process->getIncrementalOutput();

            if ($chunk !== '') {
                $this->readBuffer .= $chunk;

                continue;
            }

            $this->drainStderr();

            if (! $this->processIsRunning()) {
                throw new TransportException(
                    $this->diagnostic('The Dart Sass embedded compiler stopped unexpectedly.'),
                );
            }

            if (microtime(true) >= $deadline) {
                throw new ProtocolException($this->diagnostic('The Dart Sass embedded compiler timed out.'));
            }

            usleep(self::POLL_INTERVAL_MICROS);
        }
    }

    private function deadline(): float
    {
        return microtime(true) + $this->timeout;
    }

    private function processIsRunning(): bool
    {
        return $this->process instanceof Process && $this->process->isRunning();
    }

    private function drainStderr(): void
    {
        if (! $this->process instanceof Process) {
            return;
        }

        $chunk = $this->process->getIncrementalErrorOutput();

        if ($chunk === '') {
            return;
        }

        $this->stderr = substr($this->stderr . $chunk, -self::STDERR_TAIL_BYTES);
    }

    private function diagnostic(string $message): string
    {
        $this->drainStderr();

        return $this->stderr === '' ? $message : $message . ' stderr: ' . trim($this->stderr);
    }

    private static function field(int $number, string $value): string
    {
        return self::varint(($number << 3) | 2) . self::varint(strlen($value)) . $value;
    }

    private static function scalar(int $number, int $value): string
    {
        return self::varint($number << 3) . self::varint($value);
    }

    private static function varint(int $value): string
    {
        $encoded = '';
        do {
            $byte = $value & 0x7f;

            $value >>= 7;

            $encoded .= chr($value === 0 ? $byte : $byte | 0x80);
        } while ($value !== 0);

        return $encoded;
    }

    private static function fields(string $message): array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($message);

        while ($offset < $length) {
            $key = self::decodeVarint($message, $offset);

            $wireType = $key & 7;

            if ($wireType === 0) {
                $start = $offset;

                self::decodeVarint($message, $offset);

                $fields[$key >> 3][] = substr($message, $start, $offset - $start);

                continue;
            }

            if ($wireType === 1) {
                self::assertAvailable($length, $offset, 8);

                $fields[$key >> 3][] = substr($message, $offset, 8);

                $offset += 8;

                continue;
            }

            if ($wireType === 5) {
                self::assertAvailable($length, $offset, 4);

                $fields[$key >> 3][] = substr($message, $offset, 4);

                $offset += 4;

                continue;
            }

            if ($wireType !== 2) {
                throw new ProtocolException(
                    sprintf(
                        'Unsupported Dart Sass embedded protocol field type %d near %s.',
                        $wireType,
                        bin2hex(substr($message, max(0, $offset - 8), 16)),
                    ),
                );
            }

            $fieldLength = self::decodeVarint($message, $offset);

            self::assertAvailable($length, $offset, $fieldLength);

            $fields[$key >> 3][] = substr($message, $offset, $fieldLength);

            $offset += $fieldLength;
        }

        return $fields;
    }

    private static function assertAvailable(int $length, int $offset, int $needed): void
    {
        if ($needed < 0 || $offset > ($length - $needed)) {
            throw new ProtocolException('The Dart Sass embedded compiler sent a truncated protobuf field.');
        }
    }

    private static function integer(string $encoded): int
    {
        $offset = 0;

        return self::decodeVarint($encoded, $offset);
    }

    private static function decodeVarint(string $bytes, int &$offset): int
    {
        $value  = 0;
        $shift  = 0;
        $length = strlen($bytes);

        for ($index = 0; $index < self::MAX_VARINT_BYTES; $index++) {
            if ($offset >= $length) {
                throw new ProtocolException('The Dart Sass embedded compiler sent a truncated varint.');
            }

            $byte = ord($bytes[$offset++]);

            $payload = $byte & 0x7f;

            if ($shift >= 63 && $payload !== 0) {
                throw new ProtocolException('The Dart Sass embedded compiler sent an overflowing varint.');
            }

            $value |= $payload << $shift;

            if (($byte & 0x80) === 0) {
                return $value;
            }

            $shift += 7;
        }

        throw new ProtocolException('The Dart Sass embedded compiler sent an overlong varint.');
    }
}
