<?php declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\Exception;

beforeEach(function () {
    $this->compiler = new Compiler();

    $reflection = new ReflectionClass(Compiler::class);
    $reflection->setStaticPropertyValue('cachedProcess', null);
    $reflection->setStaticPropertyValue('cachedCommand', null);
});

it('compiles a simple SCSS string', function () {
    $scss = '$color: red; body { color: $color; }';
    $css = $this->compiler->compileString($scss);

    $expected = <<<'CSS'
body {
  color: red;
}
CSS;

    expect(trim($css))->toBe(trim($expected));
});

it('compiles with sourceMap enabled', function () {
    $scss = '$color: blue; .box { color: $color; }';

    $css = $this->compiler->compileString($scss, [
        'sourceMap' => true,
        'includeSources' => true,
    ]);

    expect($css)->toMatch('/\/\*# sourceMappingURL=data:application\/json;base64,/')
        ->and($css)->not()->toMatch('/\/\*# sourceMappingURL=.*\.map \*\//');

    preg_match('/sourceMappingURL=data:application\/json;base64,([^ ]*)/', $css, $matches);
    $encodedMap = $matches[1];
    $decodedMap = base64_decode($encodedMap);
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

    $css = $this->compiler->compileString($sass, ['syntax' => 'sass']);
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

    $css = $this->compiler->setOptions(['syntax' => 'sass'])->compileString($sass);
    expect(trim($css))->toBe(trim($expected));
});

it('throws Exception on invalid SCSS string', function () {
    $invalidScss = '$color: red body { color: $color }';
    $this->compiler->compileString($invalidScss);
})->throws(Exception::class, 'Sass parsing error:');
