<?php declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\Exception;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->compiler = new Compiler();
});

it('compiles a simple SCSS string', function () {
    $scss = '$color: red; body { color: $color; }';
    $css = $this->compiler->compileString($scss);

    $expected = <<<'CSS'
body {
  color: red;
}
CSS;

    expect($css)->toBe($expected);
});

it('compiles a SCSS file', function () {
    $file = __DIR__ . '/test.scss';
    file_put_contents($file, '$color: blue; p { color: $color; }');

    $expected = 'p{color:blue}';

    $css = $this->compiler->compileFile($file, ['compressed' => true]);
    expect($css)->toBe($expected);

    unlink($file);
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

it('compiles and saves file with sourceMap next to CSS', function () {
    $inputFile = __DIR__ . '/test_source.scss';
    $outputFile = __DIR__ . '/test_output.css';
    $mapFile = __DIR__ . '/test_output.css.map';

    file_put_contents($inputFile, '$color: green; .test { color: $color; }');

    $this->compiler->compileFileAndSave($inputFile, $outputFile, [
        'sourceMap' => true,
        'includeSources' => true,
    ]);

    expect(file_exists($outputFile))->toBeTrue()
        ->and(file_exists($mapFile))->toBeTrue();

    $css = file_get_contents($outputFile);
    expect($css)->toContain('/*# sourceMappingURL=test_output.css.map */');

    $mapContent = json_decode(file_get_contents($mapFile), true);
    expect($mapContent)->toHaveKey('sourcesContent')
        ->and($mapContent['sources'])->toBeArray();

    unlink($inputFile);
    unlink($outputFile);
    unlink($mapFile);
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

    expect($css)->toBe($expected);
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

    $expected = /** @lang text */
        <<<'CSS'
.btn {
  padding: 10px;
  background: #ff0000;
  color: white;
}
.btn:hover {
  background: #cc0000;
}
CSS;

    $css = $this->compiler->setOptions(['syntax' => 'sass'])->compileString($sass);

    expect($css)->toBe($expected);
});

it('compiles and saves file only if source has changed', function () {
    $inputFile = __DIR__ . '/test_input.scss';
    $outputFile = __DIR__ . '/test_output.css';

    file_put_contents($inputFile, '$color: red; body { color: $color; }');

    $changed = $this->compiler->compileFileAndSave($inputFile, $outputFile);
    expect($changed)->toBeTrue()
        ->and(file_exists($outputFile))->toBeTrue();

    $css = file_get_contents($outputFile);
    expect($css)->toContain('color: red');

    sleep(1);
    $changed = $this->compiler->compileFileAndSave($inputFile, $outputFile);
    expect($changed)->toBeFalse();

    file_put_contents($inputFile, '$color: blue; body { color: $color; }');
    $changed = $this->compiler->compileFileAndSave($inputFile, $outputFile);
    expect($changed)->toBeTrue();

    $css = file_get_contents($outputFile);
    expect($css)->toContain('color: blue');

    unlink($inputFile);
    unlink($outputFile);
});

it('returns empty css for empty string input in compileString', function () {
    $css = $this->compiler->compileString('');

    expect($css)->toBe('');
});

it('returns empty css for empty file in compileFile', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'scss');
    file_put_contents($tmpFile, '');

    $css = $this->compiler->compileFile($tmpFile);
    expect($css)->toBe('');

    unlink($tmpFile);
});

it('returns the options set via setOptions', function () {
    $options = [
        'syntax' => 'sass',
        'style' => 'compressed',
        'sourceMap' => true,
        'includeSources' => true,
    ];

    $this->compiler->setOptions($options);

    expect($this->compiler->getOptions())->toBe($options);
});

it('returns node path on Windows', function () {
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);

    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('isWindows')->andReturn(true);
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $node = (function() {
        return $this->findNode();
    })->call($compiler);

    expect($node)->toBeString();
});

it('returns node path on non-Windows', function () {
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);

    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('isWindows')->andReturn(false);
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $node = (function() {
        return $this->findNode();
    })->call($compiler);

    expect($node)->toBeString();
});

it('throws exception if no node candidate is successful', function () {
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('run')->atLeast()->andReturn(0);
    $mockProcess->shouldReceive('isSuccessful')->atLeast()->andReturn(false);

    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('isWindows')->andReturn(false);
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    expect(function () use ($compiler) {
        (function() {
            return $this->findNode();
        })->call($compiler);
    })->toThrow(Exception::class, 'Node.js not found');
});

it('throws Exception on invalid SCSS string', function () {
    $invalidScss = '$color: red body { color: $color }';
    $this->compiler->compileString($invalidScss);
})->throws(Exception::class, 'Sass parsing error:');

it('throws Exception when bridge.js is missing', function () {
    $bridgePath = __DIR__ . '/nonexistent_bridge.js';
    $compiler = new Compiler($bridgePath);
    $compiler->compileString('$color: red;');
})->throws(Exception::class);

it('throws Exception when file does not exist', function () {
    $this->compiler->compileFile(__DIR__ . '/nonexistent.scss');
})->throws(Exception::class);

it('throws Exception when file exists but cannot be read', function () {
    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('readFile')->andReturnFalse();

    $file = __DIR__ . '/unreadable.scss';
    file_put_contents($file, '$color: red;');

    try {
        $compiler->compileFile($file);
    } finally {
        unlink($file);
    }
})->throws(Exception::class, 'Unable to read file:');

it('throws Exception when sass process returns empty output', function () {
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn('');
    $mockProcess->shouldReceive('getErrorOutput')->once()->andReturn('some error details');

    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->__construct(nodePath: 'node');
    $compiler->shouldAllowMockingProtectedMethods();

    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    expect(fn() => $compiler->compileString('$color: red;'))
        ->toThrow(Exception::class, 'Sass process failed: some error details');
});

it('throws Exception on invalid response from sass bridge', function () {
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn('invalid-json');

    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->__construct(nodePath: 'node');
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    expect(fn() => $compiler->compileString('$color: red;'))
        ->toThrow(Exception::class, 'Invalid response from sass bridge');
});

it('throws Exception if sass-embedded folder does not exist', function () {
    $compiler = Mockery::mock(Compiler::class)->makePartial();

    $fakePackageRoot = '/non/existent/path';

    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('getPackageRoot')
        ->andReturn($fakePackageRoot);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage("sass-embedded not found. Run `npm install` in $fakePackageRoot.");

    $compiler->__construct();
});

it('throws Exception when input file does not exist in compileFileAndSave', function () {
    $this->compiler->compileFileAndSave(
        __DIR__ . '/nonexistent.scss',
        __DIR__ . '/output.css'
    );
})->throws(Exception::class);

it('processes source map with URL path', function () {
    $scss = '$color: blue; .box { color: $color; }';
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode([
        'css' => 'body{color:blue}',
        'sourceMap' => ['version' => 3, 'mappings' => '...', 'sources' => ['style.scss']]
    ]));
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $reflection = new ReflectionClass($compiler);
    $reflection->getProperty('nodePath')->setValue($compiler, 'node');
    $reflection->getProperty('bridgePath')->setValue($compiler, __DIR__ . '/../bin/bridge.js');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $css = $compiler->compileString($scss, ['sourceMap' => true, 'sourceMapPath' => 'https://example.com/style.map']);

    expect($css)->toContain('/*# sourceMappingURL=https://example.com/style.map */');
});

it('processes source map with directory path', function () {
    $testDir = __DIR__ . '/test_maps';
    if (! is_dir($testDir)) {
        mkdir($testDir);
    }

    $scss = '$color: blue; .box { color: $color; }';
    file_put_contents(__DIR__ . '/test.scss', $scss);
    $expectedMapFile = $testDir . DIRECTORY_SEPARATOR . 'style.map';

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode([
        'css' => 'body{color:blue}',
        'sourceMap' => ['version' => 3, 'mappings' => '...', 'sources' => ['https://example.com/style.scss']]
    ]));
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $reflection = new ReflectionClass($compiler);
    $reflection->getProperty('nodePath')->setValue($compiler, 'node');
    $reflection->getProperty('bridgePath')->setValue($compiler, __DIR__ . '/../bin/bridge.js');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $css = $compiler->compileFile(
        __DIR__ . '/test.scss',
        [
            'sourceMap' => true,
            'sourceMapPath' => $testDir,
            'url' => 'https://example.com/style.scss',
        ]
    );

    expect(file_exists($expectedMapFile))->toBeTrue()
        ->and($css)->toContain('/*# sourceMappingURL=style.map */');

    if (file_exists($expectedMapFile)) {
        unlink($expectedMapFile);
        rmdir($testDir);
    }

    unlink(__DIR__ . '/test.scss');
});

it('extracts filename from URL in getSourceFilenameFromUrl', function () {
    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();

    expect($compiler->getSourceFilenameFromUrl(''))->toBe('style')
        ->and($compiler->getSourceFilenameFromUrl('https://example.com/css/style.scss'))->toBe('style')
        ->and($compiler->getSourceFilenameFromUrl('https://example.com/'))->toBe('style')
        ->and($compiler->getSourceFilenameFromUrl('file:///home/user/style.scss'))->toBe('style');
});
