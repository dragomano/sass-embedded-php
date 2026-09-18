<?php

declare(strict_types=1);

namespace {
    use Bugo\Sass\EmbeddedCompiler;
    use Bugo\Sass\LogEvent;
    use Bugo\Sass\ProtocolException;
    use Symfony\Component\Process\InputStream;
    use Symfony\Component\Process\Process;

    function spawnLifecycleProcess(string $script): array
    {
        $input   = new InputStream();
        $process = new Process(
            [PHP_BINARY, '-r', $script],
        );
        $process->setInput($input);
        $process->setTimeout(null);
        $process->start();

        return [$process, $input];
    }

    function setLifecycleProcess(
        EmbeddedCompiler $compiler,
        Process $process,
        InputStream $input,
        bool $owned = false,
    ): void {
        (new ReflectionProperty(EmbeddedCompiler::class, 'process'))->setValue($compiler, $process);
        (new ReflectionProperty(EmbeddedCompiler::class, 'input'))->setValue($compiler, $input);
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
            $byte     = $value & 0x7f;
            $value  >>= 7;
            $encoded .= chr($value === 0 ? $byte : $byte | 0x80);
        } while ($value !== 0);

        return $encoded;
    }

    it('restarts a dead embedded process before the next compilation', function () {
        $compiler        = new EmbeddedCompiler();
        $processProperty = new ReflectionProperty(EmbeddedCompiler::class, 'process');

        try {
            expect($compiler->compileString('a { b: c }'))->toBe("a {\n  b: c;\n}");

            $firstProcess = $processProperty->getValue($compiler);

            $firstProcess->stop(0);

            expect($compiler->compileString('a { b: d }'))
                ->toBe("a {\n  b: d;\n}")
                ->and($processProperty->getValue($compiler))
                ->not->toBe($firstProcess);
        } finally {
            $compiler->close();
        }
    });

    it('retries one compilation after an owned transport dies', function () {
        [$process, $input] = spawnLifecycleProcess('fread(STDIN, 8192);');

        $compiler = new EmbeddedCompiler();

        setLifecycleProcess($compiler, $process, $input, true);

        try {
            expect($compiler->compileString('a { b: c }'))->toBe("a {\n  b: c;\n}");
        } finally {
            $compiler->close();
        }
    });

    it('reads split varints and preserves coalesced frames in its buffer', function () {
        $largeMessage = str_repeat('x', 130);
        $largeFrame   = lifecycleFrame(7, $largeMessage);
        $script       = sprintf(
            'fwrite(STDOUT, base64_decode(%s)); fflush(STDOUT); usleep(20000); fwrite(STDOUT, base64_decode(%s)); fflush(STDOUT); usleep(200000);',
            var_export(base64_encode($largeFrame[0]), true),
            var_export(base64_encode(substr($largeFrame, 1)), true),
        );

        [$process, $input] = spawnLifecycleProcess($script);

        $compiler = new EmbeddedCompiler();

        setLifecycleProcess($compiler, $process, $input);

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

        [$process, $input] = spawnLifecycleProcess($script);

        $compiler = new EmbeddedCompiler();

        setLifecycleProcess($compiler, $process, $input);

        try {
            expect(invokeLifecycleOn($compiler, 'readMessage', microtime(true) + 2))
                ->toBe([8, 'first'])
                ->and(invokeLifecycleOn($compiler, 'readMessage', microtime(true) + 2))
                ->toBe([9, 'second']);
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
        [$process, $input] = spawnLifecycleProcess('while (! feof(STDIN)) { fread(STDIN, 8192); }');

        $compiler = new EmbeddedCompiler();

        setLifecycleProcess($compiler, $process, $input, true);

        $compiler->close();

        expect($process->isRunning())->toBeFalse();
    });

    it('terminates a child that ignores graceful shutdown', function () {
        [$process, $input] = spawnLifecycleProcess('usleep(5000000);');

        $compiler = new EmbeddedCompiler();

        setLifecycleProcess($compiler, $process, $input, true);

        $compiler->close();

        expect($process->isRunning())->toBeFalse();
    });

    it('closes its child from the destructor even through a log handler cycle', function () {
        [$process, $input] = spawnLifecycleProcess('while (! feof(STDIN)) { fread(STDIN, 8192); }');

        $compiler = new EmbeddedCompiler();

        setLifecycleProcess($compiler, $process, $input, true);

        $compiler->setLogHandler(static function (LogEvent $event) use ($compiler): void {});

        unset($compiler);
        gc_collect_cycles();

        expect($process->isRunning())->toBeFalse();
    });
}
