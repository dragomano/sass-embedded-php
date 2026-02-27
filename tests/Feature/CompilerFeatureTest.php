<?php

declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\Exception;

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

it('compiles with sourceMap enabled', function () {
    $scss = '$color: blue; .box { color: $color; }';

    $css = $this->compiler->compileInPersistentMode($scss, [
        'sourceMap'      => true,
        'includeSources' => true,
    ]);

    expect($css)->toMatch('/\/\*# sourceMappingURL=data:application\/json;base64,/')
        ->and($css)->not()->toMatch('/\/\*# sourceMappingURL=.*\.map \*\//');

    preg_match('/sourceMappingURL=data:application\/json;base64,([^ ]*)/', $css, $matches);
    $encodedMap = $matches[1];
    $decodedMap = base64_decode($encodedMap, true);
    $mapContent = json_decode($decodedMap, true);

    expect($mapContent)->toHaveKey('version')
        ->and($mapContent)->toHaveKey('mappings')
        ->and($mapContent)->toHaveKey('sourcesContent');
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

    $css = $this->compiler->compileInPersistentMode($sass, ['syntax' => 'sass']);
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

    $css = $this->compiler->compileInPersistentMode($sass, ['syntax' => 'sass']);
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

    $css = $this->compiler->compileInPersistentMode($scss, ['style' => 'compressed']);

    expect($css)->not()->toContain('/* regular comment */')
        ->and($css)->not()->toContain('// single line comment')
        ->and($css)->toContain('/*! important comment */')
        ->and($css)->toContain('.test{color:red}');
});
