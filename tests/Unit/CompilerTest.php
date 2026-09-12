<?php

declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\Exception;
use Bugo\Sass\Options;

beforeEach(function () {
    $this->compiler = new Compiler();
});

it('stores options and merges method overrides', function () {
    $options = new Options(style: 'expanded', includeSources: true);

    expect($this->compiler->setOptions($options))->toBe($this->compiler)
        ->and($this->compiler->getOptions())->toBe($options);

    $resolved = (fn() => $this->resolveOptions(new Options(
        style: 'compressed',
        includeSources: false,
        loadPaths: [],
    )))->call($this->compiler);

    expect($resolved)->toBe([
        'style'          => 'compressed',
        'includeSources' => false,
        'loadPaths'      => [],
    ]);
});

it('returns empty css for blank and comment-only input', function () {
    expect($this->compiler->compileString(''))->toBe('')
        ->and($this->compiler->compileString('// comment'))->toBe('');
});

it('compiles indented syntax and rejects invalid syntax', function () {
    $sass = <<<'SASS'
    $color: red

    .box
      color: $color
    SASS;

    expect($this->compiler->compileString($sass, new Options(syntax: 'indented')))
        ->toBe(".box {\n  color: red;\n}")
        ->and(fn() => $this->compiler->compileString('{'))
        ->toThrow(Exception::class, 'Sass compilation error:');
});

it('reports native file compilation failures', function () {
    $input = tempnam(sys_get_temp_dir(), 'scss') . '.scss';

    file_put_contents($input, '{');

    try {
        expect(fn() => $this->compiler->compileFile($input))
            ->toThrow(Exception::class, 'Sass compilation error:');
    } finally {
        unlink($input);
    }
});

it('rejects missing source files', function () {
    $missing = __DIR__ . '/nonexistent.scss';

    expect(fn() => $this->compiler->compileFile($missing))
        ->toThrow(Exception::class, "File not found: $missing")
        ->and(fn() => $this->compiler->compileFileAndSave($missing, $missing . '.css'))
        ->toThrow(Exception::class, "Source file not found: $missing");
});

it('passes merged options to native file compilation', function () {
    $input = tempnam(sys_get_temp_dir(), 'scss') . '.scss';

    file_put_contents($input, 'a { b: c }');

    $compiler = new class (new Options(includeSources: true)) extends Compiler {
        protected function compileFileNative(string $filePath, array $options): string
        {
            return json_encode(['file' => $filePath, 'options' => $options]);
        }
    };

    try {
        $data = json_decode($compiler->compileFile($input, new Options(style: 'compressed')), true);

        expect($data['file'])->toBe($input)
            ->and($data['options'])->toBe([
                'style'          => 'compressed',
                'includeSources' => true,
            ]);
    } finally {
        unlink($input);
    }
});

it('writes only newer source files', function () {
    $input  = tempnam(sys_get_temp_dir(), 'scss') . '.scss';
    $output = tempnam(sys_get_temp_dir(), 'css') . '.css';

    file_put_contents($input, '.box { color: red; }');
    touch($output, time() - 100);

    try {
        expect($this->compiler->compileFileAndSave($input, $output))->toBeTrue()
            ->and(file_get_contents($output))->toContain('.box');

        touch($output, time() + 100);

        expect($this->compiler->compileFileAndSave($input, $output))->toBeFalse();
    } finally {
        unlink($input);
        unlink($output);
    }
});

it('handles source map modes and url precedence', function () {
    $dir   = sys_get_temp_dir() . '/sass-cli-map-' . bin2hex(random_bytes(6));
    $input = $dir . '/app.scss';

    mkdir($dir);
    file_put_contents($input, '.box { color: red; }');

    try {
        expect($this->compiler->compileFile($input))->not->toContain('sourceMappingURL')
            ->and($this->compiler->getSourceMap())->toBeNull();

        $inline = $this->compiler->compileString('.box { color: red; }', new Options(
            includeSources: true,
            sourceMapPath: 'inline',
            url: 'file:///virtual/input.scss',
            sourceFile: 'fallback.scss',
        ));
        $map = json_decode((string) $this->compiler->getSourceMap(), true);

        expect($inline)->toContain('sourceMappingURL=data:application/json;base64,')
            ->and($map['sources'])->toBe(['file:///virtual/input.scss'])
            ->and($map)->toHaveKey('sourcesContent');

        $explicit = $this->compiler->compileFile($input, new Options(sourceMapPath: $dir . '/custom.map'));

        expect($explicit)->toEndWith("\n\n/*# sourceMappingURL=custom.map */")
            ->and(json_decode((string) file_get_contents($dir . '/custom.map'), true))->toHaveKey('mappings');

        $intoDir = $this->compiler->compileFile($input, new Options(sourceMapPath: $dir));

        expect($intoDir)->toEndWith("\n\n/*# sourceMappingURL=app.css.map */")
            ->and(is_file($dir . '/app.css.map'))->toBeTrue();

        $remote = $this->compiler->compileString('.box { color: red; }', new Options(
            style: 'compressed',
            sourceMapPath: 'https://cdn.example.test/app.css.map',
        ));

        expect($remote)->toBe(".box{color:red}\n/*# sourceMappingURL=https://cdn.example.test/app.css.map */");

        set_error_handler(static fn() => true);

        expect(fn() => $this->compiler->compileFile(
            $input,
            new Options(sourceMapPath: $dir . '/absent/app.css.map')
        ))->toThrow(Exception::class, 'Unable to write the source map to');
    } finally {
        restore_error_handler();

        foreach ((array) glob($dir . '/*') as $entry) {
            unlink((string) $entry);
        }

        rmdir($dir);
    }
});

it('leaves css unchanged when the cli returns no embedded map', function () {
    $compiler = new class () extends Compiler {
        public function exposeApplySourceMap(string $css, array $options): string
        {
            return $this->applySourceMap($css, $options, 'app.scss');
        }
    };

    expect($compiler->exposeApplySourceMap('a{b:c}', ['sourceMapPath' => 'inline']))->toBe('a{b:c}')
        ->and($compiler->getSourceMap())->toBeNull();
});

it('builds every supported cli argument', function () {
    $compiler = new class () extends Compiler {
        public function exposeBuildSassArgs(array $options): array
        {
            return $this->buildSassArgs($options);
        }
    };

    expect($compiler->exposeBuildSassArgs([
        'syntax'              => 'indented',
        'style'               => 'compressed',
        'sourceMapPath'       => 'inline',
        'includeSources'      => true,
        'loadPaths'           => ['/one', '/two'],
        'quietDeps'           => true,
        'silenceDeprecations' => ['import'],
    ]))->toBe([
        '--indented',
        '--style=compressed',
        '--embed-source-map',
        '--embed-sources',
        '--load-path=/one',
        '--load-path=/two',
        '--quiet-deps',
        '--silence-deprecation=import',
    ]);
});

it('builds sass commands for supported platforms', function () {
    $compiler = new class () extends Compiler {
        public bool $windows = false;

        protected function isWindows(): bool
        {
            return $this->windows;
        }

        public function exposeSassCommand(): array
        {
            return $this->getSassCommand();
        }
    };

    $binDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin';

    expect($compiler->exposeSassCommand())->toBe([
        $binDir . DIRECTORY_SEPARATOR . 'sass',
    ]);

    $compiler->windows = true;

    expect($compiler->exposeSassCommand())->toBe([
        $binDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'dart.exe',
        $binDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'sass.snapshot',
    ]);
});
