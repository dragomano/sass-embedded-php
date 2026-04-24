<?php

declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\Options;

beforeEach(function () {
    if (! nativeSassIsAvailableForTests()) {
        test()->markTestSkipped('Native Dart Sass binary is not available in this environment.');
    }

    $this->compiler = new Compiler();
});

dataset('compiler mode variations', compilerModeVariations(...));

describe('compilation mode matrix', function () {
    it('covers the full SCSS compilation mode matrix', function () {
        expect(compilerModeVariations())->toHaveCount(6);
    });

    it('compiles every combination of source map, and style', function (array $variation) {
        $scss = compilerModeFixture();

        $css = $this->compiler->compileString($scss, new Options(
            style: $variation['style'],
            includeSources: $variation['withSourceMap'] ? true : null,
            sourceMapPath: $variation['withSourceMap'] ? 'inline' : null,
            url: 'file:///virtual/input.scss',
            sourceFile: 'input.scss',
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
        foreach ($styles as $style) {
            $styleLabel = $style ?? 'default';

            $variations["map:$withSourceMap style:$styleLabel"] = [[
                'withSourceMap' => $withSourceMap,
                'style'         => $style,
            ]];
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
    $isCompressed = $variation['style'] === 'compressed';

    if ($isCompressed) {
        return '.box{color:red}.box .child{color:rgba(255,255,0,.8)}';
    }

    return /** @lang text */ <<<'CSS'
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

function nativeSassIsAvailableForTests(): bool
{
    $root = dirname(__DIR__, 2);

    if (PHP_OS_FAMILY === 'Windows') {
        return is_file($root . '/bin/sass.bat');
    }

    return is_file($root . '/bin/sass');
}
