<?php declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\Exception;
use Symfony\Component\Process\Process;
use org\bovigo\vfs\vfsStream;

beforeEach(function () {
    $this->compiler = new Compiler();

    $this->reflection = new ReflectionClass(Compiler::class);
    $this->reflection->setStaticPropertyValue('cachedProcess', null);
    $this->reflection->setStaticPropertyValue('cachedCommand', null);
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
    $files = ['test.scss' => '$color: blue; p { color: $color; }'];
    $root = setupVfs($files);
    $file = $root . '/test.scss';
    $expected = 'p{color:blue}';

    $mockProcess = mockProcess($expected);
    $mockCompiler = mockCompiler();
    $mockCompiler->shouldReceive('createProcess')->andReturn($mockProcess);
    $mockCompiler->shouldReceive('checkEnvironment')->andReturnNull();
    $mockCompiler->shouldReceive('findNode')->andReturn('node');

    $css = $mockCompiler->compileFile($file, ['compressed' => true]);
    assertCssEquals($css, $expected);
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
    $files = ['test_source.scss' => '$color: green; .test { color: $color; }'];
    $root = setupVfs($files);
    $inputFile = $root . '/test_source.scss';
    $outputFile = $root . '/test_output.css';
    $mapFile = $root . '/test_output.css.map';
    $scss = '$color: green; .test { color: $color; }';
    $mapContent = ['version' => 3, 'sources' => ['file://' . $inputFile], 'sourcesContent' => [$scss], 'mappings' => '...'];

    $mockProcess = mockProcess('.test{color:green}', $mapContent);
    $mockCompiler = mockCompiler();
    $mockCompiler->shouldReceive('createProcess')->andReturn($mockProcess);
    $mockCompiler->shouldReceive('checkEnvironment')->andReturnNull();
    $mockCompiler->shouldReceive('findNode')->andReturn('node');

    $mockCompiler->compileFileAndSave($inputFile, $outputFile, [
        'sourceMap' => true,
        'includeSources' => true,
    ]);

    expect(file_exists($outputFile))->toBeTrue()
        ->and(file_exists($mapFile))->toBeTrue();

    $css = file_get_contents($outputFile);
    expect($css)->toContain('/*# sourceMappingURL=test_output.css.map */');

    $mapContentDecoded = json_decode(file_get_contents($mapFile), true);
    expect($mapContentDecoded)->toHaveKey('sourcesContent')
        ->and($mapContentDecoded['sources'])->toBeArray();
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

    $expected = /** @lang text */ <<<'CSS'
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
    $scss1 = '$color: red; body { color: $color; }';
    $scss2 = '$color: blue; body { color: $color; }';
    $css1 = 'body{color:red}';
    $css2 = 'body{color:blue}';

    $files = ['test_input.scss' => $scss1];
    $root = setupVfs($files);
    $inputFile = $root . '/test_input.scss';
    $outputFile = $root . '/test_output.css';

    touch($inputFile, 1000);

    $mockCompiler = mockCompiler();

    $mockProcess1 = mockProcess($css1);
    $mockProcess1->shouldReceive('isRunning')->andReturn(true);
    $mockProcess2 = mockProcess($css2);
    $mockProcess2->shouldReceive('isRunning')->andReturn(true);

    $mockCompiler->shouldReceive('readFile')->with($inputFile)->andReturn($scss1, $scss2);
    $mockCompiler->shouldReceive('createProcess')->andReturn($mockProcess1, $mockProcess2);
    $mockCompiler->shouldReceive('checkEnvironment')->andReturnNull();
    $mockCompiler->shouldReceive('findNode')->andReturn('node');

    $changed = $mockCompiler->compileFileAndSave($inputFile, $outputFile);
    expect($changed)->toBeTrue()
        ->and(file_exists($outputFile))->toBeTrue();

    $css = file_get_contents($outputFile);
    expect($css)->toContain('color:red');

    touch($outputFile, 1000);

    $changed = $mockCompiler->compileFileAndSave($inputFile, $outputFile);
    expect($changed)->toBeFalse();

    file_put_contents($inputFile, $scss2);
    touch($inputFile, 1001);

    $this->reflection->setStaticPropertyValue('cachedProcess', null);
    $this->reflection->setStaticPropertyValue('cachedCommand', null);

    $changed = $mockCompiler->compileFileAndSave($inputFile, $outputFile);
    expect($changed)->toBeTrue();

    $css = file_get_contents($outputFile);
    expect($css)->toContain('color:blue');
});

it('returns empty css for empty string input in compileString', function () {
    $css = $this->compiler->compileString('');
    expect($css)->toBe('');
});

it('returns empty css for empty file in compileFile', function () {
    $files = ['tmp.scss' => ''];
    $root = setupVfs($files);
    $tmpFile = $root . '/tmp.scss';

    $mockCompiler = mockCompiler();
    $mockCompiler->shouldReceive('checkEnvironment')->andReturnNull();
    $mockCompiler->shouldReceive('findNode')->andReturn('node');

    $css = $mockCompiler->compileFile($tmpFile);
    assertCssEquals($css, '');
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
    assertFindNodeReturnsPath(true);
});

it('returns node path on non-Windows', function () {
    assertFindNodeReturnsPath(false);
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
    $files = ['unreadable.scss' => '$color: red;'];
    $root = setupVfs($files);
    $file = $root . '/unreadable.scss';

    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('readFile')->andReturnFalse();

    $compiler->compileFile($file);
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
    $mockProcess = mockProcess('body{color:blue}', ['version' => 3, 'mappings' => '...', 'sources' => ['style.scss']]);

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $css = $compiler->compileString($scss, ['sourceMap' => true, 'sourceMapPath' => 'https://example.com/style.map']);
    expect($css)->toContain('/*# sourceMappingURL=https://example.com/style.map */');
});

it('processes source map with directory path', function () {
    $root = vfsStream::setup();
    vfsStream::newDirectory('test_maps')->at($root);
    $testDir = vfsStream::url('root/test_maps');
    $inputFile = vfsStream::url('root/test.scss');
    $scss = '$color: blue; .box { color: $color; }';
    vfsStream::newFile('test.scss')->at($root)->setContent($scss);
    $expectedMapFile = $testDir . '/style.map';

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode([
        'css' => 'body{color:blue}',
        'sourceMap' => ['version' => 3, 'mappings' => '...', 'sources' => ['https://example.com/style.scss']]
    ]));
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');

    $css = $compiler->compileFile(
        $inputFile,
        [
            'sourceMap' => true,
            'sourceMapPath' => $testDir,
            'url' => 'https://example.com/style.scss',
        ]
    );

    expect(file_exists($expectedMapFile))->toBeTrue()
        ->and($css)->toContain('/*# sourceMappingURL=style.map */');
});

it('extracts filename from URL in getSourceFilenameFromUrl', function () {
    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();

    expect($compiler->getSourceFilenameFromUrl(''))->toBe('style')
        ->and($compiler->getSourceFilenameFromUrl('https://example.com/css/style.scss'))->toBe('style')
        ->and($compiler->getSourceFilenameFromUrl('https://example.com/'))->toBe('style')
        ->and($compiler->getSourceFilenameFromUrl('file:///home/user/style.scss'))->toBe('style');
});

it('caches Node.js process between compilations for performance', function () {
    $expectedCss = <<<'CSS'
body {
  color: red;
}
CSS;

    $mockProcess = mockProcess($expectedCss);
    $mockProcess->shouldReceive('isRunning')->andReturn(true);

    $compiler = mockCompiler();

    $processCreated = false;
    $compiler->shouldReceive('createProcess')->once()->andReturnUsing(function () use ($mockProcess, &$processCreated) {
        $processCreated = true;

        return $mockProcess;
    });

    $result1 = $compiler->compileString('$color: red; body { color: $color; }');
    $result2 = $compiler->compileString('$color: red; body { color: $color; }');

    expect($processCreated)->toBeTrue()
        ->and($result1)->toBe($expectedCss)
        ->and($result2)->toBe($expectedCss);
});

it('checks that compileStringAsGenerator returns generator with correct results', function () {
    $scss = '$color: red; body { color: $color; }';
    $expectedCss = <<<'CSS'
body {
  color: red;
}
CSS;

    compileAndAssertGenerator($scss, $expectedCss);
});

it('handles large files with streaming and generators', function () {
    $scss = generateLargeScss(1024 * 1024);
    $largeVariable = str_repeat('a', 1024 * 1024);

    $expectedLargeCss = /** @lang text */ <<<'CSS'
body::after {
  content: "
CSS;
    $expectedLargeCss .= $largeVariable;
    $expectedLargeCss .= /** @lang text */ <<<'CSS'
";
}
CSS;

    compileAndAssertGenerator($scss, $expectedLargeCss, [], null, true, [$expectedLargeCss]);

    expect(strlen($scss))->toBeGreaterThan(1024 * 1024);
});

it('optimizes memory usage with cached processes during repeated compilations', function () {
    $scss = '$color: red; $size: 10px; body { color: $color; font-size: $size; }';

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setInput')->times(5)->andReturnSelf();
    $mockProcess->shouldReceive('run')->times(5)->andReturn(0);
    $expectedMemoryCss = <<<'CSS'
body {
  color: red;
  font-size: 10px;
}
CSS;

    $mockProcess->shouldReceive('getOutput')->andReturn(json_encode([
        'css' => $expectedMemoryCss,
        'sourceMap' => null
    ]));
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');
    $mockProcess->shouldReceive('isRunning')->andReturn(true);

    $compiler = mockCompiler();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);

    $initialMemory = memory_get_peak_usage(true);

    $results = [];
    for ($i = 0; $i < 5; $i++) {
        $results[] = $compiler->compileString($scss);
        $currentMemory = memory_get_peak_usage(true);

        if ($i > 0) {
            expect($currentMemory - $initialMemory)->toBeLessThan(1024 * 1024);
        }
    }

    $finalMemory = memory_get_peak_usage(true);

    foreach ($results as $result) {
        expect($result)->toBe($expectedMemoryCss);
    }

    expect($finalMemory - $initialMemory)->toBeLessThan(1024 * 1024);
});

it('returns empty result for empty string in compileStringAsGenerator', function () {
    compileAndAssertGenerator('', '');
});

it('compiles string as generator with sourceMap in streamed mode', function () {
    $scss = '$color: red; body { color: $color; }';
    $expectedCss = 'body{color:red}';
    $sourceMap = ['version' => 3, 'mappings' => '...'];

    compileAndAssertGenerator($scss, $expectedCss, ['sourceMap' => true], $sourceMap, true, [$expectedCss]);
});

it('compiles string as generator with sourceMap in non-streamed mode', function () {
    $scss = '$color: blue; body { color: $color; }';
    $expectedCss = 'body{color:blue}';
    $sourceMap = ['version' => 3, 'mappings' => '...'];

    compileAndAssertGenerator($scss, $expectedCss, ['sourceMap' => true], $sourceMap);
});

it('resets cached process when not running', function () {
    $scss = '$color: green; body { color: $color; }';
    $expectedCss = 'body{color:green}';

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setInput')->twice()->andReturnSelf();
    $mockProcess->shouldReceive('run')->twice()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->andReturn(json_encode(['css' => $expectedCss]));
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');
    $mockProcess->shouldReceive('isRunning')->andReturn(false, true);

    $compiler = mockCompiler();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);
    $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);

    $this->reflection->setStaticPropertyValue('cachedProcess', $mockProcess);
    $this->reflection->setStaticPropertyValue('cachedCommand', ['node', 'bridge.js', '--stdin']);

    $result1 = $compiler->compileString($scss);
    expect($result1)->toBe($expectedCss)
        ->and($this->reflection->getStaticPropertyValue('cachedProcess'))->toBe($mockProcess);

    $result2 = $compiler->compileString($scss);
    expect($result2)->toBe($expectedCss);
});

it('processes sourceMap with file path adding .map extension', function () {
    $scss = '$color: yellow; body { color: $color; }';
    $expectedCss = 'body{color:yellow}';
    $sourceMap = ['version' => 3, 'mappings' => '...'];
    $mapPath = vfsStream::url('root/output');
    $expectedMapFile = $mapPath . '.map';

    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode([
        'css' => $expectedCss,
        'sourceMap' => $sourceMap
    ]));
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    $compiler = mockCompiler();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $css = $compiler->compileString($scss, ['sourceMap' => true, 'sourceMapPath' => $mapPath]);
    expect($css)->toBe($expectedCss . "\n/*# sourceMappingURL=output.map */")
        ->and(file_exists($expectedMapFile))->toBeTrue();
});
