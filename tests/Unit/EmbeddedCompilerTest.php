<?php

declare(strict_types=1);

namespace Bugo\Sass {
    function proc_open($command, $descriptorspec, &$pipes, $cwd = null, $env = null, $options = null)
    {
        if ($GLOBALS['force_proc_open_failure'] ?? false) {
            return false;
        }

        return \proc_open($command, $descriptorspec, $pipes, $cwd, $env, $options);
    }
}

namespace {
    use Bugo\Sass\EmbeddedCompiler;
    use Bugo\Sass\Exception;
    use Bugo\Sass\Options;

    it('compiles multiple stylesheets in one embedded process', function () {
        $compiler = new EmbeddedCompiler();

        try {
            expect($compiler->compileString('a { b: c }'))->toBe("a {\n  b: c;\n}")
                ->and($compiler->compileString(<<<'SASS'
                $color: red

                .box
                  color: $color
                SASS, new Options(syntax: 'indented')))->toBe(".box {\n  color: red;\n}");
        } finally {
            $compiler->close();
        }
    });

    it('supports compiler options and file compilation', function () {
        $compiler = new EmbeddedCompiler(options: new Options(style: 'compressed'));
        $file     = tempnam(sys_get_temp_dir(), 'sass-embedded-') . '.scss';

        try {
            file_put_contents($file, 'a { b: c }');

            expect($compiler->getOptions())->toBeInstanceOf(Options::class)
                ->and($compiler->compileString('a { b: c }'))->toBe('a{b:c}')
                ->and($compiler->setOptions(new Options())->compileFile($file))->toBe("a {\n  b: c;\n}");
        } finally {
            $compiler->close();
            unlink($file);
        }
    });

    it('returns an empty result without starting a process for empty input', function () {
        expect((new EmbeddedCompiler())->compileString('  '))->toBe('');
    });

    it('saves changed files and rejects missing files', function () {
        $compiler = new EmbeddedCompiler();
        $input    = tempnam(sys_get_temp_dir(), 'sass-embedded-') . '.scss';
        $output   = tempnam(sys_get_temp_dir(), 'sass-embedded-') . '.css';

        try {
            file_put_contents($input, 'a { b: c }');
            touch($output, time() - 1);

            expect($compiler->compileFileAndSave($input, $output))->toBeTrue()
                ->and(file_get_contents($output))->toBe("a {\n  b: c;\n}")
                ->and($compiler->compileFileAndSave($input, $output))->toBeFalse()
                ->and(fn() => $compiler->compileFile($input . '.missing'))->toThrow(Exception::class, "File not found: $input.missing")
                ->and(fn() => $compiler->compileFileAndSave($input . '.missing', $output))->toThrow(Exception::class, "Source file not found: $input.missing");
        } finally {
            $compiler->close();
            unlink($input);
            unlink($output);
        }
    });

    it('returns Sass compilation errors and can be closed repeatedly', function () {
        $compiler = new EmbeddedCompiler();

        try {
            expect(fn() => $compiler->compileString('a {'))->toThrow(Exception::class, 'Error:');
        } finally {
            $compiler->close();
            $compiler->close();
        }
    });

    it('throws when the embedded compiler cannot be started', function () {
        $GLOBALS['force_proc_open_failure'] = true;

        try {
            expect(fn() => (new EmbeddedCompiler())->compileString('a { b: c }'))
                ->toThrow(RuntimeException::class, 'Unable to start the Dart Sass embedded compiler.');
        } finally {
            unset($GLOBALS['force_proc_open_failure']);
        }
    });

    it('encodes and decodes protobuf fields', function () {
        expect(invokeEmbedded('varint', 300))->toBe("\xac\x02")
            ->and(invokeEmbedded('integer', "\xac\x02"))->toBe(300)
            ->and(invokeEmbedded('fields', "\x08\x96\x01\x11" . str_repeat('a', 8) . "\x1d" . str_repeat('b', 4) . "\x22\x03foo"))->toBe([
                1 => ["\x96\x01"],
                2 => [str_repeat('a', 8)],
                3 => [str_repeat('b', 4)],
                4 => ['foo'],
            ])
            ->and(fn() => invokeEmbedded('fields', "\x0b"))->toThrow(RuntimeException::class, 'Unsupported Dart Sass embedded protocol field type 3');
    });

    it('encodes every supported compile option', function () {
        $options = new Options(
            style: 'compressed',
            loadPaths: ['one', 'two'],
            quietDeps: true,
            silenceDeprecations: ['import'],
            verbose: true,
        );

        $encoded = invokeEmbeddedOn(new EmbeddedCompiler(), 'compileOptions', $options);

        expect($encoded)->toContain("\x48\x01", "\x68\x01", "\x70\x01", "\x20\x01", "\x32\x05\x0a\x03one", "\x32\x05\x0a\x03two", "\x82\x01\x06import");
    });

    it('builds embedded commands for supported platforms', function () {
        expect(invokeEmbedded('command', '/project', 'Windows'))->toBe([
            '/project/bin/src/dart.exe',
            '/project/bin/src/sass.snapshot',
            '--embedded',
        ])->and(invokeEmbedded('command', '/project', 'Linux'))->toBe([
            '/project/bin/sass',
            '--embedded',
        ]);
    });

    it('handles embedded protocol errors and events', function () {
        withEmbeddedOutput("\x09\x01\x0a\x06\x1a\x04oops", function (EmbeddedCompiler $compiler): void {
            expect(fn() => $compiler->compileString('a {}'))->toThrow(RuntimeException::class, 'oops');
        });

        withEmbeddedOutput("\x03\x01\x1a\x00\x08\x01\x12\x05\x12\x03\x0a\x01x", function (EmbeddedCompiler $compiler): void {
            expect($compiler->compileString('a {}'))->toBe('x');
        });
    });

    it('rejects unsupported and mismatched embedded messages', function () {
        withEmbeddedOutput("\x03\x01\x22\x00", function (EmbeddedCompiler $compiler): void {
            expect(fn() => $compiler->compileString('a {}'))->toThrow(RuntimeException::class, 'unsupported message');
        });

        withEmbeddedOutput("\x08\x02\x12\x05\x12\x03\x0a\x01x", function (EmbeddedCompiler $compiler): void {
            expect(fn() => $compiler->compileString('a {}'))->toThrow(RuntimeException::class, 'unexpected compilation');
        });
    });

    it('reports stopped and timed out embedded processes with stderr', function () {
        withEmbeddedOutput('', function (EmbeddedCompiler $compiler): void {
            expect(fn() => $compiler->compileString('a {}'))->toThrow(RuntimeException::class, 'stopped unexpectedly');
        });

        withEmbeddedOutput("\x02", function (EmbeddedCompiler $compiler): void {
            expect(fn() => $compiler->compileString('a {}'))->toThrow(RuntimeException::class, 'stopped unexpectedly');
        });

        withEmbeddedOutput('', function (EmbeddedCompiler $compiler): void {
            expect(fn() => $compiler->compileString('a {}'))->toThrow(RuntimeException::class, 'stderr: diagnostic');
        }, timeout: 0.01, wait: true, stderr: 'diagnostic');
    });

    it('rejects failed writes and stream selection errors', function () {
        $compiler = new EmbeddedCompiler();
        $stderr   = fopen('php://temp', 'r+');
        setEmbeddedPipes($compiler, [$stderr, $stderr, $stderr]);

        try {
            expect(fn() => invokeEmbeddedOn($compiler, 'waitForStream', $stderr, microtime(true) - 1))
                ->toThrow(RuntimeException::class, 'timed out');
        } finally {
            fclose($stderr);
        }

        $compiler = new EmbeddedCompiler();
        $path     = tempnam(sys_get_temp_dir(), 'sass-embedded-');
        $read     = fopen($path, 'r');
        $stderr   = fopen('php://temp', 'r+');
        setEmbeddedPipes($compiler, [$read, $stderr, $stderr]);

        try {
            set_error_handler(static fn() => true);
            expect(fn() => invokeEmbeddedOn($compiler, 'write', 'data', microtime(true) + 1))
                ->toThrow(RuntimeException::class, 'Unable to write');
        } finally {
            restore_error_handler();
            fclose($read);
            fclose($stderr);
            unlink($path);
        }

        $compiler = new EmbeddedCompiler();
        $stream   = fopen('php://memory', 'r+');
        setEmbeddedPipes($compiler, [$stream, $stream, $stream]);

        try {
            set_error_handler(static fn() => true);
            expect(fn() => invokeEmbeddedOn($compiler, 'waitForStream', $stream, microtime(true) + 1))
                ->toThrow(RuntimeException::class, 'Unable to communicate');
        } finally {
            restore_error_handler();
            fclose($stream);
        }
    });

    function invokeEmbedded(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(EmbeddedCompiler::class, $method);

        return $reflection->invoke(null, ...$arguments);
    }

    function invokeEmbeddedOn(EmbeddedCompiler $compiler, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(EmbeddedCompiler::class, $method);

        return $reflection->invoke($compiler, ...$arguments);
    }

    function withEmbeddedOutput(string $output, Closure $assertions, float $timeout = 15, bool $wait = false, string $stderr = ''): void
    {
        $script = sprintf(
            '$output = base64_decode(%s); $stderr = base64_decode(%s); fread(STDIN, 1); fwrite(STDOUT, $output); fwrite(STDERR, $stderr); fflush(STDOUT); fflush(STDERR); %s',
            var_export(base64_encode($output), true),
            var_export(base64_encode($stderr), true),
            $wait ? 'usleep(100000);' : '',
        );

        $process = proc_open([PHP_BINARY, '-r', $script], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);

        $compiler        = new EmbeddedCompiler(timeout: $timeout);
        $processProperty = new ReflectionProperty(EmbeddedCompiler::class, 'process');
        $pipesProperty   = new ReflectionProperty(EmbeddedCompiler::class, 'pipes');

        $processProperty->setValue($compiler, $process);
        $pipesProperty->setValue($compiler, $pipes);

        try {
            $assertions($compiler);
        } finally {
            $compiler->close();
        }
    }

    function setEmbeddedPipes(EmbeddedCompiler $compiler, array $pipes): void
    {
        (new ReflectionProperty(EmbeddedCompiler::class, 'pipes'))->setValue($compiler, $pipes);
    }
}
