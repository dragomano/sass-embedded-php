<?php

declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\Exception;
use Bugo\Sass\Options;

$sharedCompiler = null;

beforeEach(function () use (&$sharedCompiler) {
    if (! $sharedCompiler instanceof Compiler) {
        $sharedCompiler = new Compiler(nodePath: 'node');
    }

    $this->compiler = $sharedCompiler;
});

afterAll(function () use (&$sharedCompiler) {
    if ($sharedCompiler instanceof Compiler) {
        $sharedCompiler->disablePersistentMode();
    }
});

dataset('compiler mode variations', compilerModeVariations(...));

describe('string compilation', function () {
    it('compiles a simple SCSS string', function () {
        $scss = '$color: red; body { color: $color; }';
        $css = $this->compiler->compileInPersistentMode($scss);

        $expected = <<<'CSS'
        body {
          color: red;
        }
        CSS;

        expect(trim($css))->toBe(trim($expected));
    });

    it('throws Exception on invalid SCSS string', function () {
        $invalidScss = '$color: red body { color: $color }';
        $this->compiler->compileInPersistentMode($invalidScss);
    })->throws(Exception::class, 'Sass parsing error:');
});

describe('source maps', function () {
    it('does not append source map without sourceMapPath', function () {
        $scss = '$color: blue; .box { color: $color; }';
        $css = $this->compiler->compileInPersistentMode($scss, new Options(includeSources: true));

        expect($css)->not()->toContain('sourceMappingURL');
    });

    it('compiles with inline sourceMapPath', function () {
        $scss = '$color: blue; .box { color: $color; }';

        $css = $this->compiler->compileInPersistentMode($scss, new Options(
            includeSources: true,
            sourceMapPath: 'inline'
        ));

        expect($css)->toMatch('/\/\*# sourceMappingURL=data:application\/json;base64,/');

        preg_match('/sourceMappingURL=data:application\/json;base64,([^ ]*)/', $css, $matches);
        $encodedMap = $matches[1];
        $decodedMap = base64_decode($encodedMap, true);
        $mapContent = json_decode($decodedMap, true);

        expect($mapContent)->toHaveKey('version')
            ->and($mapContent)->toHaveKey('mappings')
            ->and($mapContent)->toHaveKey('sourcesContent');
    });

    it('compiles with sourceMapPath enabled', function () {
        $scss = '$color: blue; .box { color: $color; }';
        $dir = sys_get_temp_dir() . '/sass-embedded-php-tests';

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $mapPath = $dir . '/feature.map';
        if (file_exists($mapPath)) {
            unlink($mapPath);
        }

        $css = $this->compiler->compileInPersistentMode($scss, new Options(
            includeSources: true,
            sourceMapPath: $mapPath
        ));

        expect($css)->toContain('/*# sourceMappingURL=feature.map */')
            ->and(file_exists($mapPath))->toBeTrue();

        $mapContent = json_decode((string) file_get_contents($mapPath), true);

        expect($mapContent)->toHaveKey('version')
            ->and($mapContent)->toHaveKey('mappings')
            ->and($mapContent)->toHaveKey('sourcesContent');

        if (file_exists($mapPath)) {
            unlink($mapPath);
        }
    });

});

describe('sass syntax', function () {
    it('compiles simple SASS syntax to CSS', function () {
        $sass = <<<'SASS'
        $primary: #333
        body
          color: $primary
        SASS;

        $expected = <<<'CSS'
        body {
          color: #333;
        }
        CSS;

        $css = $this->compiler->compileInPersistentMode($sass, new Options(syntax: 'sass'));
        expect(trim($css))->toBe(trim($expected));
    });

    it('compiles nested SASS syntax with mixin', function () {
        $sass = <<<'SASS'
        =button($color)
          padding: 10px
          background: $color
          color: white

        .btn
          +button(#ff0000)
          &:hover
            background: darken(#ff0000, 10%)
        SASS;

        $expected = /** @lang text */ <<<'CSS'
        .btn {
          color: #fff;
          background: red;
          padding: 10px;
        }

        .btn:hover {
          background: #c00;
        }
        CSS;

        $css = $this->compiler->compileInPersistentMode($sass, new Options(syntax: 'sass'));
        expect(trim($css))->toBe(trim($expected));
    });
});

describe('output transforms', function () {
    it('preserves all comments in expanded mode', function () {
        $scss = <<<'SCSS'
        /* regular comment */
        /*! important comment */
        // single line comment
        .test {
            color: red;
        }
        SCSS;

        $css = $this->compiler->compileInPersistentMode($scss);

        expect($css)->toContain('/* regular comment */')
            ->and($css)->toContain('/*! important comment */')
            ->and($css)->toContain("/* regular comment */\n/*! important comment */")
            ->and($css)->toContain("/*! important comment */\n.test {")
            ->and($css)->not()->toContain('// single line comment')
            ->and($css)->toContain('.test {')
            ->and($css)->toContain('color: red;');
    });

    it('preserves only important comments in compressed mode', function () {
        $scss = <<<'SCSS'
        /* regular comment */
        /*! important comment */
        // single line comment
        .test {
            color: red;
        }
        SCSS;

        $css = $this->compiler->compileInPersistentMode($scss, new Options(style: 'compressed'));

        expect($css)->not()->toContain('/* regular comment */')
            ->and($css)->not()->toContain('// single line comment')
            ->and($css)->toContain('/*! important comment */')
            ->and($css)->toContain('.test{color:red}');
    });

    it('can disable Lightning CSS optimization for compressed output', function () {
        $scss = <<<'SCSS'
        .test {
            color: rgba(255, 255, 0, 0.8);
        }
        SCSS;

        $optimizedCss = $this->compiler->compileInPersistentMode($scss, new Options(style: 'compressed'));
        $sassOnlyCss = $this->compiler->compileInPersistentMode($scss, new Options(
            style: 'compressed',
            optimizeCss: false,
        ));

        expect($optimizedCss)->toBe('.test{color:#ff0c}')
            ->and($sassOnlyCss)->toBe('.test{color:rgba(255,255,0,.8)}');
    });
});

describe('compilation mode matrix', function () {
    it('covers the full SCSS compilation mode matrix', function () {
        expect(compilerModeVariations())->toHaveCount(24);
    });

    it('compiles every combination of source map, optimizeCss, removeEmptyLines, and style', function (array $variation) {
        $scss = compilerModeFixture();

        $css = $this->compiler->compileString($scss, new Options(
            style: $variation['style'],
            optimizeCss: $variation['optimizeCss'],
            removeEmptyLines: $variation['removeEmptyLines'],
            includeSources: $variation['withSourceMap'] ? true : null,
            sourceMapPath: $variation['withSourceMap'] ? 'inline' : null,
            sourceFile: 'input.scss',
            url: 'file:///virtual/input.scss',
        ));

        expect($css)->toStartWith(expectedCssBodyForVariation($variation));

        if (! $variation['withSourceMap']) {
            expect($css)->not()->toContain('sourceMappingURL');

            return;
        }

        preg_match('/sourceMappingURL=data:application\/json;base64,([^ ]*)/', $css, $matches);

        $encodedMap = $matches[1] ?? null;
        $decodedMap = $encodedMap !== null ? base64_decode($encodedMap, true) : false;
        $mapContent = $decodedMap !== false ? json_decode($decodedMap, true) : null;

        expect($encodedMap)->not()->toBeNull()
            ->and($mapContent)->toBeArray()
            ->and($mapContent)->toMatchArray([
                'version'        => 3,
                'sourceRoot'     => '',
                'sources'        => ['file:///virtual/input.scss'],
                'names'          => [],
                'mappings'       => expectedMappingsForStyle($variation['style']),
                'sourcesContent' => [compilerModeFixture()],
            ]);
    })->with('compiler mode variations');
});

function compilerModeVariations(): array
{
    $variations = [];
    $styles = [null, 'expanded', 'compressed'];

    foreach ([false, true] as $withSourceMap) {
        foreach ([true, false] as $optimizeCss) {
            foreach ([true, false] as $removeEmptyLines) {
                foreach ($styles as $style) {
                    $styleLabel = $style ?? 'default';

                    $variations["map:$withSourceMap opt:$optimizeCss empty:$removeEmptyLines style:$styleLabel"] = [[
                        'withSourceMap'    => $withSourceMap,
                        'optimizeCss'      => $optimizeCss,
                        'removeEmptyLines' => $removeEmptyLines,
                        'style'            => $style,
                    ]];
                }
            }
        }
    }

    return $variations;
}

function compilerModeFixture(): string
{
    return <<<'SCSS'
    $color: red;

    .box {
      color: $color;

      .child {
        color: rgba(255, 255, 0, 0.8);
      }
    }
    SCSS;
}

function expectedCssBodyForVariation(array $variation): string
{
    $isCompressed             = $variation['style'] === 'compressed';
    $usesLightningCss         = ! $variation['withSourceMap'] && $variation['optimizeCss'];
    $hasBlankLineBetweenRules = $usesLightningCss && ! $variation['removeEmptyLines'];

    if ($isCompressed) {
        return $usesLightningCss
            ? '.box{color:red}.box .child{color:#ff0c}'
            : '.box{color:red}.box .child{color:rgba(255,255,0,.8)}';
    }

    if ($usesLightningCss) {
        return $hasBlankLineBetweenRules
            ? <<<'CSS'
            .box {
              color: red;
            }

            .box .child {
              color: #ff0c;
            }
            CSS
            : <<<'CSS'
            .box {
              color: red;
            }
            .box .child {
              color: #ff0c;
            }
            CSS;
    }

    return <<<'CSS'
    .box {
      color: red;
    }
    .box .child {
      color: rgba(255, 255, 0, 0.8);
    }
    CSS;
}

function expectedMappingsForStyle(?string $style): string
{
    return $style === 'compressed'
        ? 'AAEA,KACE,MAHM,IAKN,YACE'
        : 'AAEA;EACE,OAHM;;AAKN;EACE';
}
