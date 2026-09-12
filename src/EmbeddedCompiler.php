<?php

declare(strict_types=1);

namespace Bugo\Sass;

use Closure;
use ValueError;

final class EmbeddedCompiler implements CompilerInterface
{
    use HandlesSourceMaps;

    private const PROTOCOL_MAJOR = 3;

    private const READ_CHUNK_SIZE = 8192;

    private const MAX_VARINT_BYTES = 10;

    private const MAX_PACKET_LENGTH = 268_435_456;

    private const CLOSE_TIMEOUT = 0.5;

    private const TERMINATE_TIMEOUT = 0.25;

    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

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

        $url = self::stringUrl($options);

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

        $pipes = $this->pipes;

        $this->process     = null;
        $this->pipes       = [];
        $this->stderr      = '';
        $this->readBuffer  = '';
        $this->ownsProcess = false;

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        if (is_resource($process) && ! self::waitForProcessExit($process, $pipes, self::CLOSE_TIMEOUT)) {
            @proc_terminate($process);

            self::waitForProcessExit($process, $pipes, self::TERMINATE_TIMEOUT);
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($process)) {
            @proc_close($process);
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

        $id = $this->nextId++;

        $request = $input . $this->compileOptions($options);

        $this->send($id, self::field(2, $request), $deadline);

        while (true) {
            [$responseId, $message] = $this->readMessage($deadline);

            $outbound = self::fields($message);

            if (isset($outbound[1][0])) {
                $error = self::fields($outbound[1][0]);

                throw new ProtocolException(
                    $error[3][0] ?? 'The Dart Sass embedded compiler rejected the request.'
                );
            }

            if (isset($outbound[3][0])) {
                $this->log(self::fields($outbound[3][0]));

                continue;
            }

            if (! isset($outbound[2][0])) {
                throw new ProtocolException(
                    'The Dart Sass embedded compiler sent an unsupported message.'
                );
            }

            if ($responseId !== $id) {
                throw new ProtocolException(
                    'The Dart Sass embedded compiler returned a response for an unexpected compilation.'
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
                    $name
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
            $event[5][0] ?? ''
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

        $drive = '';

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

        if (is_resource($this->process) || $this->pipes !== []) {
            $this->close();
        }

        $root = dirname(__DIR__);

        $this->process = proc_open(
            self::command($root, PHP_OS_FAMILY),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $this->pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (! is_resource($this->process)) {
            throw new ProtocolException('Unable to start the Dart Sass embedded compiler.');
        }

        $this->ownsProcess = true;

        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $this->stderr     = '';
        $this->readBuffer = '';

        $this->handshake();
    }

    private function handshake(): void
    {
        $deadline = $this->deadline();

        $this->send(0, self::field(7, ''), $deadline);

        [, $message] = $this->readMessage($deadline);

        $outbound = self::fields($message);

        if (isset($outbound[1][0])) {
            $error = self::fields($outbound[1][0]);

            throw new ProtocolException(
                $error[3][0] ?? 'The Dart Sass embedded compiler rejected the version request.'
            );
        }

        if (! isset($outbound[8][0])) {
            throw new ProtocolException(
                'The Dart Sass embedded compiler did not respond to the version request.'
            );
        }

        $version = self::fields($outbound[8][0])[1][0] ?? '';

        if ($version === '') {
            throw new ProtocolException(
                'The Dart Sass embedded compiler did not report its protocol version.'
            );
        }

        if ((int) explode('.', $version)[0] !== self::PROTOCOL_MAJOR) {
            throw new ProtocolException(
                sprintf(
                    'Unsupported Sass embedded protocol version %s: this package speaks %d.x. Install a matching Dart Sass release.',
                    $version,
                    self::PROTOCOL_MAJOR
                )
            );
        }
    }

    private function send(int $compilationId, string $message, float $deadline): void
    {
        $packet = self::varint($compilationId) . $message;

        $this->write(self::varint(strlen($packet)) . $packet, $deadline);
    }

    private static function command(string $root, string $osFamily): array
    {
        return $osFamily === 'Windows'
            ? [$root . '/bin/src/dart.exe', $root . '/bin/src/sass.snapshot', '--embedded']
            : [$root . '/bin/sass', '--embedded'];
    }

    private function write(string $data, float $deadline): void
    {
        while ($data !== '') {
            if ($this->ownsProcess && ! $this->processIsRunning()) {
                throw new TransportException(
                    $this->diagnostic('The Dart Sass embedded compiler stopped unexpectedly before writing.')
                );
            }

            $this->waitForStream($this->pipes[0], $deadline, false);

            $written = fwrite($this->pipes[0], $data);

            if ($written === false || $written === 0) {
                throw new TransportException(
                    $this->diagnostic('Unable to write to the Dart Sass embedded compiler.')
                );
            }

            $data = substr($data, $written);
        }

        fflush($this->pipes[0]);
    }

    private function readMessage(float $deadline): array
    {
        $length = $this->readVarint($deadline);

        if ($length < 1) {
            throw new ProtocolException('The Dart Sass embedded compiler sent an empty packet.');
        }

        if ($length > self::MAX_PACKET_LENGTH) {
            throw new ProtocolException(
                sprintf('The Dart Sass embedded compiler sent an oversized packet (%d bytes).', $length)
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
            while (strlen($this->readBuffer) <= $index) {
                $this->readFromStdout($deadline);
            }

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
            $this->readFromStdout($deadline);
        }
    }

    private function readFromStdout(float $deadline): void
    {
        $this->waitForStream($this->pipes[1], $deadline);

        $chunk = fread($this->pipes[1], self::READ_CHUNK_SIZE);

        if ($chunk === '' || $chunk === false) {
            throw new TransportException(
                $this->diagnostic('The Dart Sass embedded compiler stopped unexpectedly.')
            );
        }

        $this->readBuffer .= $chunk;
    }

    private function deadline(): float
    {
        return microtime(true) + $this->timeout;
    }

    private function waitForStream($stream, float $deadline, bool $read = true): void
    {
        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new ProtocolException($this->diagnostic('The Dart Sass embedded compiler timed out.'));
            }

            $seconds      = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);

            $reads  = $read ? [$stream, $this->pipes[2]] : [];
            $writes = $read ? [] : [$stream];
            $except = [];

            try {
                $ready = @stream_select($reads, $writes, $except, $seconds, $microseconds);
            } catch (ValueError) {
                $ready = false;
            }

            if ($ready === false) {
                throw new TransportException($this->diagnostic('Unable to communicate with the Dart Sass embedded compiler.'));
            }

            if ($ready === 0) {
                throw new ProtocolException($this->diagnostic('The Dart Sass embedded compiler timed out.'));
            }

            if ($read && in_array($this->pipes[2], $reads, true)) {
                $this->drainStderr();
            }

            if (! $read || in_array($stream, $reads, true)) {
                return;
            }
        }
    }

    private function processIsRunning(): bool
    {
        if (! is_resource($this->process)) {
            return false;
        }

        $status = @proc_get_status($this->process);

        return is_array($status) && ($status['running'] ?? false);
    }

    private static function waitForProcessExit($process, array $pipes, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;

        do {
            foreach ([1, 2] as $index) {
                if (isset($pipes[$index])) {
                    self::drainAvailableStream($pipes[$index]);
                }
            }

            $status = @proc_get_status($process);
            if (! is_array($status) || ! ($status['running'] ?? false)) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private static function drainAvailableStream($stream): void
    {
        if (! is_resource($stream)) {
            return;
        }

        while (true) {
            $reads  = [$stream];
            $writes = [];
            $except = [];

            try {
                $ready = @stream_select($reads, $writes, $except, 0, 0);
            } catch (ValueError) {
                return;
            }

            if ($ready !== 1) {
                return;
            }

            $chunk = fread($stream, self::READ_CHUNK_SIZE);

            if ($chunk === '' || $chunk === false) {
                return;
            }
        }
    }

    private function drainStderr(): void
    {
        while (($chunk = fread($this->pipes[2], self::READ_CHUNK_SIZE)) !== '' && $chunk !== false) {
            $this->stderr = substr($this->stderr . $chunk, -self::READ_CHUNK_SIZE);
        }
    }

    private function diagnostic(string $message): string
    {
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            $this->drainStderr();
        }

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
                        bin2hex(substr($message, max(0, $offset - 8), 16))
                    )
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
        if ($needed < 0 || $offset > $length - $needed) {
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
