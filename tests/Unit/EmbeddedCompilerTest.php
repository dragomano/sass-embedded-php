<?php

declare(strict_types=1);

namespace {
    use Bugo\Sass\EmbeddedCompiler;
    use Bugo\Sass\Exception;
    use Bugo\Sass\LogEvent;
    use Bugo\Sass\LogType;
    use Bugo\Sass\Options;
    use Bugo\Sass\ProtocolException;
    use Symfony\Component\Process\InputStream;
    use Symfony\Component\Process\Process;

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

    function withEmbeddedOutput(
        string $output,
        Closure $assertions,
        array $options = [],
    ): void {
        $timeout    = $options['timeout'] ?? 15.0;
        $wait       = $options['wait'] ?? false;
        $stderr     = $options['stderr'] ?? '';
        $maxRetries = $options['maxRetries'] ?? 1;
        $script     = sprintf(
            '$output = base64_decode(%s); $stderr = base64_decode(%s); fread(STDIN, 1); fwrite(STDOUT, $output); fwrite(STDERR, $stderr); fflush(STDOUT); fflush(STDERR); %s',
            var_export(base64_encode($output), true),
            var_export(base64_encode($stderr), true),
            $wait ? 'usleep(500000);' : '',
        );

        $input   = new InputStream();
        $process = new Process(
            [PHP_BINARY, '-r', $script],
        );
        $process->setInput($input);
        $process->setTimeout(null);
        $process->start();
        $input->write('x');

        $compiler        = new EmbeddedCompiler(timeout: $timeout);
        $processProperty = new ReflectionProperty(EmbeddedCompiler::class, 'process');
        $inputProperty   = new ReflectionProperty(EmbeddedCompiler::class, 'input');
        $ownsProperty    = new ReflectionProperty(EmbeddedCompiler::class, 'ownsProcess');
        $retryProperty   = new ReflectionProperty(EmbeddedCompiler::class, 'maxRetries');

        $processProperty->setValue($compiler, $process);
        $inputProperty->setValue($compiler, $input);
        $ownsProperty->setValue($compiler, true);
        $retryProperty->setValue($compiler, $maxRetries);

        try {
            $assertions($compiler);
        } finally {
            $compiler->close();
        }
    }

    it('compiles multiple stylesheets in one embedded process', function () {
        $compiler = new EmbeddedCompiler();

        try {
            expect($compiler->compileString('a { b: c }'))
                ->toBe("a {\n  b: c;\n}")
                ->and($compiler->compileString(<<<'SASS'
                    $color: red

                    .box
                      color: $color
                    SASS, new Options(syntax: 'indented')))
                ->toBe(".box {\n  color: red;\n}");
        } finally {
            $compiler->close();
        }
    });

    it('supports compiler options and file compilation', function () {
        $compiler = new EmbeddedCompiler(options: new Options(style: 'compressed'));
        $file     = tempnam(sys_get_temp_dir(), 'sass-embedded-') . '.scss';

        try {
            file_put_contents($file, 'a { b: c }');

            expect($compiler->getOptions())
                ->toBeInstanceOf(Options::class)
                ->and($compiler->compileString('a { b: c }'))
                ->toBe('a{b:c}')
                ->and($compiler->setOptions(new Options())->compileFile($file))
                ->toBe("a {\n  b: c;\n}");
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

            expect($compiler->compileFileAndSave($input, $output))
                ->toBeTrue()
                ->and(file_get_contents($output))
                ->toBe("a {\n  b: c;\n}")
                ->and($compiler->compileFileAndSave($input, $output))
                ->toBeFalse()
                ->and(fn() => $compiler->compileFile($input . '.missing'))
                ->toThrow(Exception::class, "File not found: $input.missing")
                ->and(fn() => $compiler->compileFileAndSave($input . '.missing', $output))
                ->toThrow(Exception::class, "Source file not found: $input.missing");
        } finally {
            $compiler->close();
            unlink($input);
            unlink($output);
        }
    });

    it('reports Sass compilation errors without killing the process', function () {
        $compiler = new EmbeddedCompiler();
        $process  = new ReflectionProperty(EmbeddedCompiler::class, 'process');

        try {
            $compiler->compileString('a { b: c }');
            $started = $process->getValue($compiler);

            expect(fn() => $compiler->compileString('a {'))
                ->toThrow(Exception::class, 'Error:')
                ->and($process->getValue($compiler))
                ->toBe($started)
                ->and($compiler->compileString('a { b: c }'))
                ->toBe("a {\n  b: c;\n}");
        } finally {
            $compiler->close();
            $compiler->close();
        }
    });

    it('encodes and decodes protobuf fields', function () {
        expect(invokeEmbedded('varint', 300))
            ->toBe("\xac\x02")
            ->and(invokeEmbedded('integer', "\xac\x02"))
            ->toBe(300)
            ->and(invokeEmbedded(
                'fields',
                "\x08\x96\x01\x11" . str_repeat('a', 8) . "\x1d" . str_repeat('b', 4) . "\x22\x03foo",
            ))
            ->toBe([
                1 => ["\x96\x01"],
                2 => [str_repeat('a', 8)],
                3 => [str_repeat('b', 4)],
                4 => ['foo'],
            ])
            ->and(fn() => invokeEmbedded('fields', "\x0b"))
            ->toThrow(ProtocolException::class, 'Unsupported Dart Sass embedded protocol field type 3');
    });

    it('encodes every supported compile option', function () {
        $options = new Options(
            style: 'compressed',
            includeSources: true,
            loadPaths: ['one', 'two'],
            quietDeps: true,
            silenceDeprecations: ['import'],
            verbose: true,
            sourceMapPath: 'inline',
        );

        $encoded = invokeEmbeddedOn(new EmbeddedCompiler(), 'compileOptions', $options);

        expect($encoded)
            ->toContain(
                "\x48\x01",
                "\x68\x01",
                "\x50\x01",
                "\x58\x01",
                "\x20\x01",
                "\x28\x01",
                "\x60\x01",
                "\x32\x05\x0a\x03one",
                "\x32\x05\x0a\x03two",
                "\x82\x01\x06import",
            )
            // The `silent` field would suppress every LogEvent, including @warn and @debug.
            ->and($encoded)
            ->not->toContain("\x70\x01");
    });

    it('resolves canonical urls and source map targets', function () {
        expect(invokeEmbedded('hasUrlScheme', 'https://example.test/app.css.map'))
            ->toBeTrue()
            ->and(invokeEmbedded('hasUrlScheme', 'file:///tmp/app.scss'))
            ->toBeTrue()
            ->and(invokeEmbedded('hasUrlScheme', 'C:/out/app.css.map'))
            ->toBeFalse()
            ->and(invokeEmbedded('hasUrlScheme', '/out/app.css.map'))
            ->toBeFalse()
            ->and(invokeEmbedded('fileUrl', '/tmp/a b.scss'))
            ->toBe('file:///tmp/a%20b.scss')
            ->and(invokeEmbedded('fileUrl', 'C:\\out\\app.scss'))
            ->toBe('file:///C:/out/app.scss')
            ->and(invokeEmbedded('fileUrl', 'app.scss'))
            ->toStartWith('file:///')
            ->and(invokeEmbedded('fileUrl', 'app.scss'))
            ->toEndWith('/app.scss')
            ->and(invokeEmbedded('stringUrl', new Options()))
            ->toBe('')
            ->and(invokeEmbedded('stringUrl', new Options(url: 'file:///x/y.scss')))
            ->toBe('file:///x/y.scss')
            ->and(invokeEmbedded('stringUrl', new Options(sourceFile: 'app.scss')))
            ->toEndWith('/app.scss')
            ->and(invokeEmbedded('sourceMapPath', '/out/app.css.map', 'app.scss'))
            ->toBe('/out/app.css.map')
            ->and(invokeEmbedded('sourceMapPath', '/out/', 'nested/app.scss'))
            ->toBe('/out/app.css.map')
            ->and(invokeEmbedded('sourceMapPath', '/out/', 'file:///virtual/input.scss'))
            ->toBe('/out/input.css.map')
            ->and(invokeEmbedded('sourceMapPath', '/out/', ''))
            ->toBe('/out/style.css.map');
    });

    it('emits source maps in every supported sourceMapPath mode', function () {
        $compiler = new EmbeddedCompiler();
        $dir      = sys_get_temp_dir() . '/sass-map-' . bin2hex(random_bytes(6));
        $input    = $dir . '/app.scss';

        mkdir($dir);
        file_put_contents($input, 'a { b: c }');

        try {
            expect($compiler->compileString('a { b: c }'))
                ->toBe("a {\n  b: c;\n}")
                ->and($compiler->getSourceMap())
                ->toBeNull();

            $inline = $compiler->compileString('a { b: c }', new Options(
                includeSources: true,
                sourceMapPath: 'inline',
                url: 'file:///virtual/input.scss',
            ));

            $map = json_decode((string) $compiler->getSourceMap(), true);

            expect($inline)
                ->toStartWith("a {\n  b: c;\n}\n\n/*# sourceMappingURL=data:application/json;base64,")
                ->and($inline)
                ->toEndWith(' */')
                ->and($map['sources'])
                ->toBe(['file:///virtual/input.scss'])
                ->and($map)
                ->toHaveKey('sourcesContent');

            $explicit = $compiler->compileFile($input, new Options(sourceMapPath: $dir . '/custom.map'));

            expect($explicit)
                ->toEndWith("\n\n/*# sourceMappingURL=custom.map */")
                ->and(json_decode((string) file_get_contents($dir . '/custom.map'), true))
                ->toHaveKey('mappings');

            $intoDir = $compiler->compileFile($input, new Options(sourceMapPath: $dir));

            expect($intoDir)
                ->toEndWith("\n\n/*# sourceMappingURL=app.css.map */")
                ->and(is_file($dir . '/app.css.map'))
                ->toBeTrue();

            $remote = $compiler->compileString('a { b: c }', new Options(
                style: 'compressed',
                sourceMapPath: 'https://cdn.example.test/app.css.map',
            ));

            expect($remote)
                ->toBe("a{b:c}\n/*# sourceMappingURL=https://cdn.example.test/app.css.map */")
                ->and($compiler->getSourceMap())
                ->not->toBeNull();

            set_error_handler(static fn() => true);

            expect(fn() => $compiler->compileFile($input, new Options(sourceMapPath: $dir . '/absent/app.css.map')))
                ->toThrow(Exception::class, 'Unable to write the source map to');
        } finally {
            restore_error_handler();
            $compiler->close();

            foreach ((array) glob($dir . '/*') as $entry) {
                unlink((string) $entry);
            }

            rmdir($dir);
        }
    });

    it('builds embedded commands for supported platforms', function () {
        expect(invokeEmbedded('command', '/project', 'Windows'))
            ->toBe([
                '/project/bin/src/dart.exe',
                '/project/bin/src/sass.snapshot',
                '--embedded',
            ])
            ->and(invokeEmbedded('command', '/project', 'Linux'))
            ->toBe([
                '/project/bin/sass',
                '--embedded',
            ]);
    });

    it('handles embedded protocol errors and events', function () {
        withEmbeddedOutput("\x09\x01\x0a\x06\x1a\x04oops", function (EmbeddedCompiler $compiler): void {
            expect(fn() => $compiler->compileString('a {}'))->toThrow(ProtocolException::class, 'oops');
        });

        withEmbeddedOutput("\x03\x01\x1a\x00\x08\x01\x12\x05\x12\x03\x0a\x01x", function (EmbeddedCompiler $compiler): void {
            expect($compiler->compileString('a {}'))
                ->toBe('x')
                ->and($compiler->getLogs())
                ->toHaveCount(1)
                ->and($compiler->getLogs()[0]->type)
                ->toBe(LogType::Warning);
        });
    });

    it('verifies the compiler protocol version on startup', function () {
        // OutboundMessage.version_response { protocol_version: "3.2.0" }
        withEmbeddedOutput("\x0a\x00\x42\x07\x0a\x05" . '3.2.0', function (EmbeddedCompiler $compiler): void {
            expect(invokeEmbeddedOn($compiler, 'handshake'))->toBeNull();
        });

        withEmbeddedOutput("\x0a\x00\x42\x07\x0a\x05" . '2.0.0', function (EmbeddedCompiler $compiler): void {
            expect(fn() => invokeEmbeddedOn($compiler, 'handshake'))
                ->toThrow(ProtocolException::class, 'Unsupported Sass embedded protocol version 2.0.0');
        });

        // A compile_response instead of a version_response.
        withEmbeddedOutput("\x03\x00\x12\x00", function (EmbeddedCompiler $compiler): void {
            expect(fn() => invokeEmbeddedOn($compiler, 'handshake'))
                ->toThrow(ProtocolException::class, 'did not respond to the version request');
        });

        withEmbeddedOutput("\x03\x00\x42\x00", function (EmbeddedCompiler $compiler): void {
            expect(fn() => invokeEmbeddedOn($compiler, 'handshake'))
                ->toThrow(ProtocolException::class, 'did not report its protocol version');
        });

        withEmbeddedOutput("\x09\x00\x0a\x06\x1a\x04oops", function (EmbeddedCompiler $compiler): void {
            expect(fn() => invokeEmbeddedOn($compiler, 'handshake'))->toThrow(ProtocolException::class, 'oops');
        });
    });

    it('collects log events emitted during compilation', function () {
        $debug = "\x21\x01\x1a\x1e\x10\x02\x1a\x05hello\x2a\x05trace\x32\x04fmtd\x3a\x06import";
        $alien = "\x09\x01\x1a\x06\x10\x07\x1a\x02hi";
        $done  = "\x08\x01\x12\x05\x12\x03\x0a\x01x";

        withEmbeddedOutput($debug . $alien . $done, function (EmbeddedCompiler $compiler): void {
            $seen = [];

            $compiler->setLogHandler(static function (LogEvent $event) use (&$seen): void {
                $seen[] = $event->message;
            });

            expect($compiler->compileString('a {}'))->toBe('x');

            [$first, $second] = $compiler->getLogs();

            expect($seen)
                ->toBe(['hello', 'hi'])
                ->and($first->type)
                ->toBe(LogType::Debug)
                ->and($first->message)
                ->toBe('hello')
                ->and($first->formatted)
                ->toBe('fmtd')
                ->and($first->deprecationType)
                ->toBe('import')
                ->and($first->stackTrace)
                ->toBe('trace')
                // An unknown LogEventType degrades to a plain warning.
                ->and($second->type)
                ->toBe(LogType::Warning)
                ->and($second->deprecationType)
                ->toBeNull();
        });
    });

    it('rejects unsupported and mismatched embedded messages', function () {
        withEmbeddedOutput("\x03\x01\x22\x00", function (EmbeddedCompiler $compiler): void {
            expect(fn() => $compiler->compileString('a {}'))
                ->toThrow(ProtocolException::class, 'unsupported message');
        });

        withEmbeddedOutput("\x08\x02\x12\x05\x12\x03\x0a\x01x", function (EmbeddedCompiler $compiler): void {
            expect(fn() => $compiler->compileString('a {}'))
                ->toThrow(ProtocolException::class, 'unexpected compilation');
        });
    });

    it('reports stopped and timed out embedded processes with stderr', function () {
        withEmbeddedOutput(
            '',
            function (EmbeddedCompiler $compiler): void {
                expect(fn() => $compiler->compileString('a {}'))
                    ->toThrow(ProtocolException::class, 'stopped unexpectedly');
            },
            options: ['maxRetries' => 0],
        );

        withEmbeddedOutput(
            "\x02",
            function (EmbeddedCompiler $compiler): void {
                expect(fn() => $compiler->compileString('a {}'))
                    ->toThrow(ProtocolException::class, 'stopped unexpectedly');
            },
            options: ['maxRetries' => 0],
        );

        withEmbeddedOutput(
            '',
            function (EmbeddedCompiler $compiler): void {
                expect(fn() => $compiler->compileString('a {}'))
                    ->toThrow(ProtocolException::class, 'stderr: diagnostic');
            },
            options: ['timeout' => 0.1, 'wait' => true, 'stderr' => 'diagnostic'],
        );
    });

    it('rejects writes to a closed input stream', function () {
        $compiler = new EmbeddedCompiler();
        $input    = new InputStream();
        $process  = new Process([PHP_BINARY, '-r', 'usleep(100000);']);
        $process->setInput($input);
        $process->setTimeout(null);
        $process->start();
        $input->close();

        (new ReflectionProperty(EmbeddedCompiler::class, 'process'))->setValue($compiler, $process);
        (new ReflectionProperty(EmbeddedCompiler::class, 'input'))->setValue($compiler, $input);
        (new ReflectionProperty(EmbeddedCompiler::class, 'ownsProcess'))->setValue($compiler, true);

        try {
            expect(fn() => invokeEmbeddedOn($compiler, 'write', 'data'))
                ->toThrow(ProtocolException::class, 'Unable to write');
        } finally {
            $compiler->close();
        }
    });
}
