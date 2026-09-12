<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('runs the cold and warm benchmark matrix', function () {
    $root = dirname(__DIR__, 2);

    if (! benchmarkNativeSassIsAvailable($root)) {
        test()->markTestSkipped('Native Dart Sass binary is not available in this environment.');
    }

    $process = new Process([
        PHP_BINARY,
        $root . '/benchmark.php',
        '--runs=1',
        '--warmup=0',
        '--batch=1',
        '--profile=small',
        '--input=string',
        '--no-write',
    ], $root);

    $process->setTimeout(120);
    $process->run();

    expect($process->isSuccessful())
        ->toBeTrue($process->getErrorOutput() ?: $process->getOutput())
        ->and($process->getOutput())->toContain(
            '| small | string | cold | CLI Compiler |',
            '| small | string | cold | EmbeddedCompiler |',
            '| small | string | warm | EmbeddedCompiler |',
            '| small | string | warm | scssphp/scssphp |'
        );
});

function benchmarkNativeSassIsAvailable(string $root): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        return is_file($root . '/bin/sass.bat');
    }

    return is_file($root . '/bin/sass');
}
