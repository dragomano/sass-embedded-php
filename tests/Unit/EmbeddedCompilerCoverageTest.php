<?php

declare(strict_types=1);

use Bugo\Sass\EmbeddedCompiler;
use Bugo\Sass\ProtocolException;
use Bugo\Sass\TransportException;

it('rejects empty and oversized embedded packets', function () {
    $compiler = new EmbeddedCompiler();
    $buffer   = new ReflectionProperty(EmbeddedCompiler::class, 'readBuffer');

    $buffer->setValue($compiler, coverageVarint(0));

    expect(fn() => invokeCoverageMethod($compiler, 'readMessage', microtime(true) + 1))
        ->toThrow(ProtocolException::class, 'empty packet');

    $buffer->setValue($compiler, coverageVarint(268_435_457));

    expect(fn() => invokeCoverageMethod($compiler, 'readMessage', microtime(true) + 1))
        ->toThrow(ProtocolException::class, 'oversized packet');
});

it('rejects overflowing and overlong frame varints', function () {
    $compiler = new EmbeddedCompiler();
    $buffer   = new ReflectionProperty(EmbeddedCompiler::class, 'readBuffer');

    $buffer->setValue($compiler, str_repeat("\x80", 9) . "\x02");

    expect(fn() => invokeCoverageMethod($compiler, 'readVarint', microtime(true) + 1))
        ->toThrow(ProtocolException::class, 'overflowing varint');

    $buffer->setValue($compiler, str_repeat("\x80", 10));

    expect(fn() => invokeCoverageMethod($compiler, 'readVarint', microtime(true) + 1))
        ->toThrow(ProtocolException::class, 'overlong varint');
});

it('stops after the configured number of transport retries', function () {
    $process = proc_open(
        [PHP_BINARY, '-r', 'fread(STDIN, 8192);'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $compiler = new EmbeddedCompiler();
    setCoverageProcess($compiler, $process, $pipes, true);
    (new ReflectionProperty(EmbeddedCompiler::class, 'maxRetries'))->setValue($compiler, 0);

    try {
        expect(fn() => $compiler->compileString('a { b: c }'))
            ->toThrow(TransportException::class, 'stopped unexpectedly');
    } finally {
        $compiler->close();
    }
});

it('detects an owned process that died immediately before writing', function () {
    $process = proc_open(
        [PHP_BINARY, '-r', ''],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $deadline = microtime(true) + 1;

    do {
        $status = proc_get_status($process);

        if (! $status['running']) {
            break;
        }

        usleep(1_000);
    } while (microtime(true) < $deadline);

    $compiler = new EmbeddedCompiler();
    setCoverageProcess($compiler, $process, $pipes, true);

    try {
        expect(fn() => invokeCoverageMethod($compiler, 'write', 'data', microtime(true) + 1))
            ->toThrow(TransportException::class, 'stopped unexpectedly before writing');
    } finally {
        $compiler->close();
    }
});

it('closes unexpected extra pipes', function () {
    $extra    = fopen('php://temp', 'r+');
    $compiler = new EmbeddedCompiler();

    (new ReflectionProperty(EmbeddedCompiler::class, 'pipes'))->setValue($compiler, [3 => $extra]);
    $compiler->close();

    expect(is_resource($extra))->toBeFalse();
});

it('drains closed, invalid, and exhausted streams safely', function () {
    $closed = fopen('php://temp', 'r+');
    fclose($closed);

    expect(invokeCoverageStatic('drainAvailableStream', $closed))->toBeNull();

    $invalidProcess = proc_open(
        [PHP_BINARY, '-r', 'usleep(100000);'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $invalidPipes,
        null,
        null,
        ['bypass_shell' => true],
    );

    expect(invokeCoverageStatic('drainAvailableStream', $invalidProcess))->toBeNull();

    foreach ($invalidPipes as $pipe) {
        fclose($pipe);
    }

    proc_terminate($invalidProcess);
    proc_close($invalidProcess);

    $process = proc_open(
        [PHP_BINARY, '-r', ''],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    usleep(20_000);

    expect(invokeCoverageStatic('drainAvailableStream', $pipes[1]))->toBeNull();

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
});

function setCoverageProcess(EmbeddedCompiler $compiler, $process, array $pipes, bool $owned): void
{
    (new ReflectionProperty(EmbeddedCompiler::class, 'process'))->setValue($compiler, $process);
    (new ReflectionProperty(EmbeddedCompiler::class, 'pipes'))->setValue($compiler, $pipes);
    (new ReflectionProperty(EmbeddedCompiler::class, 'ownsProcess'))->setValue($compiler, $owned);
}

function invokeCoverageMethod(EmbeddedCompiler $compiler, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(EmbeddedCompiler::class, $method))->invokeArgs($compiler, $arguments);
}

function invokeCoverageStatic(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(EmbeddedCompiler::class, $method))->invokeArgs(null, $arguments);
}

function coverageVarint(int $value): string
{
    $encoded = '';

    do {
        $byte    = $value & 0x7f;
        $value   >>= 7;
        $encoded .= chr($value === 0 ? $byte : $byte | 0x80);
    } while ($value !== 0);

    return $encoded;
}
