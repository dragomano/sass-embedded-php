<?php

declare(strict_types=1);

namespace Bugo\Sass;

use RuntimeException;
use ValueError;

final class EmbeddedCompiler implements CompilerInterface
{
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private int $nextId = 1;

    private string $stderr = '';

    public function __construct(private readonly float $timeout = 15.0, private Options $options = new Options()) {}

    public function __destruct()
    {
        $this->close();
    }

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

        $options ??= $this->options;
        $input = self::field(1, $source);

        if ($options->syntax === 'indented') {
            $input .= self::scalar(3, 1);
        }

        return $this->compile(self::field(2, $input), $options);
    }

    public function compileFile(string $path, ?Options $options = null): string
    {
        if (! file_exists($path)) {
            throw new Exception("File not found: $path");
        }

        return $this->compile(self::field(3, $path), $options ?? $this->options);
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
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes  = [];
        $this->stderr = '';

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;
    }

    private function compile(string $input, Options $options): string
    {
        try {
            $this->start();

            $deadline = $this->deadline();
            $id       = $this->nextId++;
            $request  = $input . $this->compileOptions($options);
            $message  = self::field(2, $request);
            $packet   = self::varint($id) . $message;

            $this->write(self::varint(strlen($packet)) . $packet, $deadline);

            while (true) {
                [$responseId, $message] = $this->readMessage($deadline);

                $outbound = self::fields($message);

                if (isset($outbound[1][0])) {
                    $error = self::fields($outbound[1][0]);

                    throw new RuntimeException($error[3][0] ?? 'The Dart Sass embedded compiler rejected the request.');
                }

                if (isset($outbound[3][0])) {
                    continue;
                }

                if (! isset($outbound[2][0])) {
                    throw new RuntimeException('The Dart Sass embedded compiler sent an unsupported message.');
                }

                if ($responseId !== $id) {
                    throw new RuntimeException('The Dart Sass embedded compiler returned a response for an unexpected compilation.');
                }

                $response = self::fields($outbound[2][0]);

                if (isset($response[2][0])) {
                    $success = self::fields($response[2][0]);

                    return $success[1][0] ?? '';
                }

                $failure = self::fields($response[3][0] ?? '');

                throw new Exception($failure[4][0] ?? $failure[1][0] ?? 'Sass embedded compilation failed.');
            }
        } catch (RuntimeException $runtimeException) {
            $this->close();

            throw $runtimeException;
        }
    }

    private function compileOptions(Options $options): string
    {
        $request = self::scalar(9, 1) . self::scalar(13, 1) . self::scalar(14, 1);

        if ($options->style === 'compressed') {
            $request .= self::scalar(4, 1);
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
        if (is_resource($this->process)) {
            return;
        }

        $root = dirname(__DIR__);

        $command = self::command($root, PHP_OS_FAMILY);

        $this->process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $this->pipes, null, null, ['bypass_shell' => true]);

        if (! is_resource($this->process)) {
            throw new RuntimeException('Unable to start the Dart Sass embedded compiler.');
        }

        stream_set_blocking($this->pipes[0], false);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    /** @return list<string> */
    private static function command(string $root, string $osFamily): array
    {
        return $osFamily === 'Windows'
            ? [$root . '/bin/src/dart.exe', $root . '/bin/src/sass.snapshot', '--embedded']
            : [$root . '/bin/sass', '--embedded'];
    }

    private function write(string $data, float $deadline): void
    {
        while ($data !== '') {
            $this->waitForStream($this->pipes[0], $deadline, false);

            $written = fwrite($this->pipes[0], $data);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write to the Dart Sass embedded compiler.');
            }

            $data = substr($data, $written);
        }

        fflush($this->pipes[0]);
    }

    /** @return array{int, string} */
    private function readMessage(float $deadline): array
    {
        $length = $this->readVarint($deadline);
        $packet = '';

        while (strlen($packet) < $length) {
            $this->waitForStream($this->pipes[1], $deadline);

            $chunk = fread($this->pipes[1], $length - strlen($packet));

            if ($chunk === '' || $chunk === false) {
                throw new RuntimeException('The Dart Sass embedded compiler stopped unexpectedly.');
            }

            $packet .= $chunk;
        }

        $offset = 0;

        return [self::decodeVarint($packet, $offset), substr($packet, $offset)];
    }

    private function readVarint(float $deadline): int
    {
        $value = 0;
        $shift = 0;

        do {
            $this->waitForStream($this->pipes[1], $deadline);

            $byte = fread($this->pipes[1], 1);

            if ($byte === '' || $byte === false) {
                throw new RuntimeException('The Dart Sass embedded compiler stopped unexpectedly.');
            }

            $number = ord($byte);
            $value |= ($number & 0x7f) << $shift;
            $shift += 7;
        } while (($number & 0x80) !== 0);

        return $value;
    }

    private function deadline(): float
    {
        return microtime(true) + $this->timeout;
    }

    /** @param resource $stream */
    private function waitForStream($stream, float $deadline, bool $read = true): void
    {
        while (true) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                throw new RuntimeException($this->diagnostic('The Dart Sass embedded compiler timed out.'));
            }

            $seconds      = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $reads        = $read ? [$stream, $this->pipes[2]] : [];
            $writes       = $read ? [] : [$stream];
            $except       = [];

            try {
                $ready = @stream_select($reads, $writes, $except, $seconds, $microseconds);
            } catch (ValueError) {
                $ready = false;
            }

            if ($ready === false) {
                throw new RuntimeException($this->diagnostic('Unable to communicate with the Dart Sass embedded compiler.'));
            }

            if ($ready === 0) {
                throw new RuntimeException($this->diagnostic('The Dart Sass embedded compiler timed out.'));
            }

            if ($read && in_array($this->pipes[2], $reads, true)) {
                $this->drainStderr();
            }

            if (! $read || in_array($stream, $reads, true)) {
                return;
            }
        }
    }

    private function drainStderr(): void
    {
        while (($chunk = fread($this->pipes[2], 8192)) !== '' && $chunk !== false) {
            $this->stderr = substr($this->stderr . $chunk, -8192);
        }
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

    /** @return array<int, list<string>> */
    private static function fields(string $message): array
    {
        $fields = [];
        $offset = 0;

        while ($offset < strlen($message)) {
            $key      = self::decodeVarint($message, $offset);
            $wireType = $key & 7;

            if ($wireType === 0) {
                $start = $offset;
                self::decodeVarint($message, $offset);
                $fields[$key >> 3][] = substr($message, $start, $offset - $start);

                continue;
            }

            if ($wireType === 1) {
                $fields[$key >> 3][] = substr($message, $offset, 8);
                $offset += 8;

                continue;
            }

            if ($wireType === 5) {
                $fields[$key >> 3][] = substr($message, $offset, 4);
                $offset += 4;

                continue;
            }

            if ($wireType !== 2) {
                throw new RuntimeException(sprintf(
                    'Unsupported Dart Sass embedded protocol field type %d near %s.',
                    $wireType,
                    bin2hex(substr($message, max(0, $offset - 8), 16)),
                ));
            }

            $length = self::decodeVarint($message, $offset);
            $fields[$key >> 3][] = substr($message, $offset, $length);
            $offset += $length;
        }

        return $fields;
    }

    private static function integer(string $encoded): int
    {
        $offset = 0;

        return self::decodeVarint($encoded, $offset);
    }

    private static function decodeVarint(string $bytes, int &$offset): int
    {
        $value = 0;
        $shift = 0;

        do {
            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7f) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return $value;
    }
}
