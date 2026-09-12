<?php

declare(strict_types=1);

namespace Bugo\Sass {
    function proc_terminate($process, $signal = 15): bool
    {
        if ($GLOBALS['track_embedded_proc_terminate'] ?? false) {
            $GLOBALS['embedded_proc_terminate_calls'] = ($GLOBALS['embedded_proc_terminate_calls'] ?? 0) + 1;
        }

        return \proc_terminate($process, $signal);
    }
}

namespace {
    use Bugo\Sass\EmbeddedCompiler;
    use Bugo\Sass\LogEvent;
    use Bugo\Sass\ProtocolException;

    afterEach(function (): void {
        unset($GLOBALS['track_embedded_proc_terminate'], $GLOBALS['embedded_proc_terminate_calls']);
    });

    it('restarts a dead embedded process before the next compilation', function () {
        $compiler        = new EmbeddedCompiler();
        $processProperty = new ReflectionProperty(EmbeddedCompiler::class, 'process');

        try {
            expect($compiler->compileString('a { b: c }'))->toBe("a {\n  b: c;\n}");

            $firstProcess = $processProperty->getValue($compiler);

            \proc_terminate($firstProcess);

            $deadline = microtime(true) + 2;

            do {
                $status = proc_get_status($firstProcess);

                if (! $status['running']) {
                    break;
                }

                usleep(10_000);
            } while (microtime(true) < $deadline);

            expect($compiler->compileString('a { b: d }'))->toBe("a {\n  b: d;\n}")
                ->and($processProperty->getValue($compiler))->not->toBe($firstProcess);
        } finally {
            $compiler->close();
        }
    });

    it('retries one compilation after an owned transport dies', function () {
        [$process, $pipes] = spawnLifecycleProcess('fread(STDIN, 8192);');
        $compiler = new EmbeddedCompiler();
        setLifecycleProcess($compiler, $process, $pipes, true);

        try {
            expect($compiler->compileString('a { b: c }'))->toBe("a {\n  b: c;\n}");
        } finally {
            $compiler->close();
        }
    });

    it('reads split varints and preserves coalesced frames in its buffer', function () {
        $largeMessage = str_repeat('x', 130);
        $largeFrame = lifecycleFrame(7, $largeMessage);
        $script = sprintf(
            'fwrite(STDOUT, base64_decode(%s)); fflush(STDOUT); usleep(20000); fwrite(STDOUT, base64_decode(%s)); fflush(STDOUT); usleep(200000);',
            var_export(base64_encode($largeFrame[0]), true),
            var_export(base64_encode(substr($largeFrame, 1)), true),
        );
        [$process, $pipes] = spawnLifecycleProcess($script);
        $compiler = new EmbeddedCompiler();
        setLifecycleProcess($compiler, $process, $pipes);

        try {
            expect(invokeLifecycleOn($compiler, 'readMessage', microtime(true) + 2))->toBe([7, $largeMessage]);
        } finally {
            $compiler->close();
        }

        $frames = lifecycleFrame(8, 'first') . lifecycleFrame(9, 'second');
        $script = sprintf(
            'fwrite(STDOUT, base64_decode(%s)); fflush(STDOUT); usleep(200000);',
            var_export(base64_encode($frames), true),
        );
        [$process, $pipes] = spawnLifecycleProcess($script);
        $compiler = new EmbeddedCompiler();
        setLifecycleProcess($compiler, $process, $pipes);

        try {
            expect(invokeLifecycleOn($compiler, 'readMessage', microtime(true) + 2))->toBe([8, 'first'])
                ->and(invokeLifecycleOn($compiler, 'readMessage', microtime(true) + 2))->toBe([9, 'second']);
        } finally {
            $compiler->close();
        }
    });

    it('rejects truncated, overflowing, and overlong protobuf values', function () {
        expect(fn() => invokeLifecycle('integer', "\x80"))
            ->toThrow(ProtocolException::class, 'truncated varint')
            ->and(fn() => invokeLifecycle('integer', str_repeat("\x80", 10)))
            ->toThrow(ProtocolException::class, 'overlong varint')
            ->and(fn() => invokeLifecycle('integer', str_repeat("\x80", 9) . "\x02"))
            ->toThrow(ProtocolException::class, 'overflowing varint')
            ->and(fn() => invokeLifecycle('fields', "\x09abc"))
            ->toThrow(ProtocolException::class, 'truncated protobuf field')
            ->and(fn() => invokeLifecycle('fields', "\x0a\x05abc"))
            ->toThrow(ProtocolException::class, 'truncated protobuf field');
    });

    it('lets a cooperative process exit without terminating it', function () {
        [$process, $pipes] = spawnLifecycleProcess('while (! feof(STDIN)) { fread(STDIN, 8192); }');
        $compiler = new EmbeddedCompiler();
        setLifecycleProcess($compiler, $process, $pipes, true);
        $GLOBALS['track_embedded_proc_terminate'] = true;
        $GLOBALS['embedded_proc_terminate_calls'] = 0;

        $compiler->close();

        expect($GLOBALS['embedded_proc_terminate_calls'])->toBe(0)
            ->and(is_resource($process))->toBeFalse();
    });

    it('terminates a child that ignores graceful shutdown', function () {
        [$process, $pipes] = spawnLifecycleProcess('usleep(5000000);');
        $compiler = new EmbeddedCompiler();
        setLifecycleProcess($compiler, $process, $pipes, true);
        $GLOBALS['track_embedded_proc_terminate'] = true;
        $GLOBALS['embedded_proc_terminate_calls'] = 0;

        $compiler->close();

        expect($GLOBALS['embedded_proc_terminate_calls'])->toBe(1)
            ->and(is_resource($process))->toBeFalse();
    });

    it('closes its child from the destructor even through a log handler cycle', function () {
        [$process, $pipes] = spawnLifecycleProcess('while (! feof(STDIN)) { fread(STDIN, 8192); }');
        $compiler = new EmbeddedCompiler();
        setLifecycleProcess($compiler, $process, $pipes, true);
        $compiler->setLogHandler(static function (LogEvent $event) use ($compiler): void {});

        unset($compiler);
        gc_collect_cycles();

        expect(is_resource($process))->toBeFalse();
    });

    function spawnLifecycleProcess(string $script): array
    {
        $process = proc_open([PHP_BINARY, '-r', $script], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        return [$process, $pipes];
    }

    function setLifecycleProcess(EmbeddedCompiler $compiler, $process, array $pipes, bool $owned = false): void
    {
        (new ReflectionProperty(EmbeddedCompiler::class, 'process'))->setValue($compiler, $process);
        (new ReflectionProperty(EmbeddedCompiler::class, 'pipes'))->setValue($compiler, $pipes);
        (new ReflectionProperty(EmbeddedCompiler::class, 'ownsProcess'))->setValue($compiler, $owned);
    }

    function invokeLifecycle(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(EmbeddedCompiler::class, $method))->invokeArgs(null, $arguments);
    }

    function invokeLifecycleOn(EmbeddedCompiler $compiler, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(EmbeddedCompiler::class, $method))->invokeArgs($compiler, $arguments);
    }

    function lifecycleFrame(int $compilationId, string $message): string
    {
        $packet = lifecycleVarint($compilationId) . $message;

        return lifecycleVarint(strlen($packet)) . $packet;
    }

    function lifecycleVarint(int $value): string
    {
        $encoded = '';

        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            $encoded .= chr($value === 0 ? $byte : $byte | 0x80);
        } while ($value !== 0);

        return $encoded;
    }
}
