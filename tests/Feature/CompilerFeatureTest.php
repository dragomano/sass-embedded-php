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

it('throws Exception on invalid SCSS string', function () {
    $invalidScss = '$color: red body { color: $color }';
    $this->compiler->compileInPersistentMode($invalidScss);
})->throws(Exception::class, 'Sass parsing error:');

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
