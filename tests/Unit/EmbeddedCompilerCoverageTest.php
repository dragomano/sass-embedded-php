<?php

declare(strict_types=1);

use Bugo\Sass\EmbeddedCompiler;
use Bugo\Sass\ProtocolException;
use Bugo\Sass\TransportException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

function spawnCoverageProcess(string $script): array
{
    $input   = new InputStream();
    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->setInput($input);
    $process->setTimeout(null);
    $process->start();

    if ($script === '') {
        $process->wait();
    }

    return [$process, $input];
}

function setCoverageProcess(EmbeddedCompiler $compiler, Process $process, InputStream $input, bool $owned): void
{
    (new ReflectionProperty(EmbeddedCompiler::class, 'process'))->setValue($compiler, $process);
    (new ReflectionProperty(EmbeddedCompiler::class, 'input'))->setValue($compiler, $input);
    (new ReflectionProperty(EmbeddedCompiler::class, 'ownsProcess'))->setValue($compiler, $owned);
}

function invokeCoverageMethod(EmbeddedCompiler $compiler, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(EmbeddedCompiler::class, $method))->invokeArgs($compiler, $arguments);
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

it('rejects empty and oversized embedded packets', function () {
    $compiler = new EmbeddedCompiler();

    $buffer = new ReflectionProperty(EmbeddedCompiler::class, 'readBuffer');
    $buffer->setValue($compiler, coverageVarint(0));

    expect(fn() => invokeCoverageMethod($compiler, 'readMessage', microtime(true) + 1))
        ->toThrow(ProtocolException::class, 'empty packet');

    $buffer->setValue($compiler, coverageVarint(268_435_457));

    expect(fn() => invokeCoverageMethod($compiler, 'readMessage', microtime(true) + 1))
        ->toThrow(ProtocolException::class, 'oversized packet');
});

it('rejects overflowing and overlong frame varints', function () {
    $compiler = new EmbeddedCompiler();

    $buffer = new ReflectionProperty(EmbeddedCompiler::class, 'readBuffer');
    $buffer->setValue($compiler, str_repeat("\x80", 9) . "\x02");

    expect(fn() => invokeCoverageMethod($compiler, 'readVarint', microtime(true) + 1))
        ->toThrow(ProtocolException::class, 'overflowing varint');

    $buffer->setValue($compiler, str_repeat("\x80", 10));

    expect(fn() => invokeCoverageMethod($compiler, 'readVarint', microtime(true) + 1))
        ->toThrow(ProtocolException::class, 'overlong varint');
});

it('stops after the configured number of transport retries', function () {
    [$process, $input] = spawnCoverageProcess('fread(STDIN, 8192);');

    $compiler = new EmbeddedCompiler();

    setCoverageProcess($compiler, $process, $input, true);

    (new ReflectionProperty(EmbeddedCompiler::class, 'maxRetries'))->setValue($compiler, 0);

    try {
        expect(fn() => $compiler->compileString('a { b: c }'))
            ->toThrow(TransportException::class, 'stopped unexpectedly');
    } finally {
        $compiler->close();
    }
});

it('detects an owned process that died immediately before writing', function () {
    [$process, $input] = spawnCoverageProcess('');

    $compiler = new EmbeddedCompiler();
    setCoverageProcess($compiler, $process, $input, true);

    try {
        expect(fn() => invokeCoverageMethod($compiler, 'write', 'data'))
            ->toThrow(TransportException::class, 'stopped unexpectedly before writing');
    } finally {
        $compiler->close();
    }
});

it('closes an input stream even when it is already closed', function () {
    $input = Mockery::mock(InputStream::class);
    $input->shouldReceive('close')->once()->andThrow(new RuntimeException('already closed'));

    $compiler = new EmbeddedCompiler();

    (new ReflectionProperty(EmbeddedCompiler::class, 'input'))->setValue($compiler, $input);

    $compiler->close();

    expect(true)->toBeTrue();
});

it('wraps process startup failures in a protocol exception', function () {
    $compiler = new EmbeddedCompiler(processFactory: static function (): never {
        throw new RuntimeException('spawn failed');
    });

    expect(fn() => $compiler->compileString('a {}'))
        ->toThrow(ProtocolException::class, 'Unable to start the Dart Sass embedded compiler: spawn failed');
});

it('rejects a process factory that returns the wrong type', function () {
    $compiler = new EmbeddedCompiler(processFactory: static fn(array $command): object => new stdClass());

    expect(fn() => $compiler->compileString('a {}'))
        ->toThrow(ProtocolException::class, 'The process factory did not return a Symfony Process instance.');
});

it('detects a process that exits immediately after accepting input', function () {
    [$process, $input] = spawnCoverageProcess('fread(STDIN, 8192);');

    $compiler = new EmbeddedCompiler();

    setCoverageProcess($compiler, $process, $input, true);

    try {
        $deadline  = microtime(true) + 2;
        $exception = null;

        while (microtime(true) < $deadline && $exception === null) {
            set_error_handler(static fn(): bool => true);

            try {
                invokeCoverageMethod($compiler, 'write', str_repeat('x', 8192));
            } catch (TransportException $caught) {
                $exception = $caught;
            } finally {
                restore_error_handler();
            }

            usleep(1_000);
        }

        expect($exception)->toBeInstanceOf(TransportException::class);
    } finally {
        $compiler->close();
    }
});

it('detects a process that exits immediately after writing', function () {
    $input   = new InputStream();
    $process = new class([PHP_BINARY]) extends Process {
        private int $checks = 0;

        public function isRunning(): bool
        {
            return ++$this->checks === 2;
        }

        public function getIncrementalErrorOutput(): string
        {
            return '';
        }

        public function resetChecks(): void
        {
            $this->checks = 0;
        }
    };

    $process->resetChecks();
    $process->setInput($input);

    $compiler = new EmbeddedCompiler();

    setCoverageProcess($compiler, $process, $input, true);

    try {
        expect(fn() => invokeCoverageMethod($compiler, 'write', 'data'))
            ->toThrow(TransportException::class, 'stopped unexpectedly after writing');
    } finally {
        $compiler->close();
    }
});

it('ignores stderr draining when no process exists', function () {
    expect(invokeCoverageMethod(new EmbeddedCompiler(), 'drainStderr'))->toBeNull();
});
