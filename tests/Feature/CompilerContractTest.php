<?php

declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\CompilerInterface;
use Bugo\Sass\EmbeddedCompiler;
use Bugo\Sass\Exception;
use Bugo\Sass\Options;

beforeEach(function () {
    if (! contractNativeSassIsAvailable()) {
        test()->markTestSkipped('Native Dart Sass binary is not available in this environment.');
    }
});

dataset('compiler implementations', [
    'CLI compiler'      => [Compiler::class],
    'embedded compiler' => [EmbeddedCompiler::class],
]);

it('implements the shared options contract', function (string $compilerClass) {
    $compiler = new $compilerClass();
    $options  = new Options(style: 'compressed');

    try {
        expect($compiler)
            ->toBeInstanceOf(CompilerInterface::class)
            ->and($compiler->setOptions($options))
            ->toBe($compiler)
            ->and($compiler->getOptions())
            ->toBe($options);
    } finally {
        closeContractCompiler($compiler);
    }
})->with('compiler implementations');

it('merges method options with instance defaults', function (string $compilerClass) {
    $compiler = new $compilerClass();

    $compiler->setOptions(new Options(
        style: 'expanded',
        includeSources: true,
        quietDeps: true,
        sourceMapPath: 'inline',
        url: 'file:///virtual/default.scss',
        sourceFile: 'default.scss',
    ));

    try {
        $css = $compiler->compileString('a { b: c }', new Options(
            style: 'compressed',
            quietDeps: false,
            loadPaths: [],
            silenceDeprecations: [],
            url: 'file:///virtual/override.scss',
            sourceFile: 'override.scss',
        ));

        $map = json_decode((string) $compiler->getSourceMap(), true);

        expect($css)
            ->toStartWith('a{b:c}')
            ->and($css)
            ->toContain('sourceMappingURL=data:application/json;base64,')
            ->and($map['sources'])
            ->toBe(['file:///virtual/override.scss'])
            ->and($map)
            ->toHaveKey('sourcesContent');
    } finally {
        closeContractCompiler($compiler);
    }
})->with('compiler implementations');

it('returns empty css for blank and comment-only input', function (string $compilerClass) {
    $compiler = new $compilerClass();

    try {
        expect($compiler->compileString(" \n\t "))
            ->toBe('')
            ->and($compiler->compileString('// comment only'))
            ->toBe('');
    } finally {
        closeContractCompiler($compiler);
    }
})->with('compiler implementations');

it('compiles expanded, compressed, and indented string input', function (string $compilerClass) {
    $compiler = new $compilerClass();

    try {
        expect($compiler->compileString('a { b: c }'))
            ->toBe("a {\n  b: c;\n}")
            ->and($compiler->compileString('a { b: c }', new Options(style: 'compressed')))
            ->toBe('a{b:c}')
            ->and($compiler->compileString(<<<'SASS'
                $color: red

                .box
                  color: $color
                SASS, new Options(syntax: 'indented')))
            ->toBe(".box {\n  color: red;\n}");
    } finally {
        closeContractCompiler($compiler);
    }
})->with('compiler implementations');

it('compiles files through load paths', function (string $compilerClass) {
    $compiler = new $compilerClass();
    $dir      = sys_get_temp_dir() . '/sass-contract-' . bin2hex(random_bytes(6));
    $input    = $dir . '/app.scss';
    $loadPath = $dir . '/modules';

    mkdir($loadPath, 0777, true);
    file_put_contents($loadPath . '/_colors.scss', '$accent: blue;');
    file_put_contents($input, '@use "colors"; .box { color: colors.$accent; }');

    try {
        expect($compiler->compileFile($input, new Options(loadPaths: [$loadPath])))
            ->toBe(".box {\n  color: blue;\n}");
    } finally {
        closeContractCompiler($compiler);
        unlink($loadPath . '/_colors.scss');
        unlink($input);
        rmdir($loadPath);
        rmdir($dir);
    }
})->with('compiler implementations');

it('saves only when the source file is newer', function (string $compilerClass) {
    $compiler = new $compilerClass();
    $dir      = sys_get_temp_dir() . '/sass-contract-' . bin2hex(random_bytes(6));
    $input    = $dir . '/app.scss';
    $output   = $dir . '/app.css';

    mkdir($dir);
    file_put_contents($input, 'a { b: c }');
    file_put_contents($output, 'old');
    touch($output, time() - 10);

    try {
        expect($compiler->compileFileAndSave($input, $output))
            ->toBeTrue()
            ->and(file_get_contents($output))
            ->toBe("a {\n  b: c;\n}");

        touch($output, time() + 10);

        expect($compiler->compileFileAndSave($input, $output))->toBeFalse();
    } finally {
        closeContractCompiler($compiler);
        unlink($input);
        unlink($output);
        rmdir($dir);
    }
})->with('compiler implementations');

it('uses the shared missing file errors', function (string $compilerClass) {
    $compiler = new $compilerClass();
    $missing  = sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(6)) . '.scss';

    try {
        expect(fn() => $compiler->compileFile($missing))
            ->toThrow(Exception::class, "File not found: $missing")
            ->and(fn() => $compiler->compileFileAndSave($missing, $missing . '.css'))
            ->toThrow(Exception::class, "Source file not found: $missing");
    } finally {
        closeContractCompiler($compiler);
    }
})->with('compiler implementations');

it('gives url precedence in compatible inline source maps', function (string $compilerClass) {
    $compiler = new $compilerClass();

    try {
        $css = $compiler->compileString('.box { color: red; }', new Options(
            includeSources: true,
            sourceMapPath: 'inline',
            url: 'file:///virtual/contract.scss',
            sourceFile: 'fallback.scss',
        ));

        $map = json_decode((string) $compiler->getSourceMap(), true);

        expect($css)
            ->toContain('sourceMappingURL=data:application/json;base64,')
            ->and($map)
            ->toBeArray()
            ->and($map['version'])
            ->toBe(3)
            ->and($map['sources'])
            ->toBe(['file:///virtual/contract.scss'])
            ->and($map)
            ->toHaveKey('sourcesContent');
    } finally {
        closeContractCompiler($compiler);
    }
})->with('compiler implementations');

function closeContractCompiler(CompilerInterface $compiler): void
{
    if ($compiler instanceof EmbeddedCompiler) {
        $compiler->close();
    }
}

function contractNativeSassIsAvailable(): bool
{
    $root = dirname(__DIR__, 2);

    if (PHP_OS_FAMILY === 'Windows') {
        return is_file($root . '/bin/sass.bat');
    }

    return is_file($root . '/bin/sass');
}
