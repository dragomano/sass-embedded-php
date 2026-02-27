<?php

declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\Exception;
use Bugo\Sass\TimeOverrides;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->compiler = new Compiler(nodePath: 'node');

    $reflection = new ReflectionClass(Compiler::class);
    $reflection->setStaticPropertyValue('cachedProcess', null);
    $reflection->setStaticPropertyValue('cachedCommand', null);
});

it('compiles a SCSS file', function () {
    $files    = ['test.scss' => '$color: blue; p { color: $color; }'];
    $root     = setupVfs($files);
    $file     = $root . '/test.scss';
    $expected = 'p{color:blue}';

    $mockProcess = mockProcess($expected);
    $mockCompiler = mockCompiler();
    $mockCompiler->shouldReceive('createProcess')->andReturn($mockProcess);
    $mockCompiler->shouldReceive('checkEnvironment')->andReturnNull();
    $mockCompiler->shouldReceive('findNode')->andReturn('node');

    $css = $mockCompiler->compileFile($file, ['compressed' => true]);
    assertCssEquals($css, $expected);
});

it('compiles and saves file with sourceMap next to CSS', function () {
    $files      = ['test_source.scss' => '$color: green; .test { color: $color; }'];
    $root       = setupVfs($files);
    $inputFile  = $root . '/test_source.scss';
    $outputFile = $root . '/test_output.css';
    $mapFile    = $root . '/test_output.css.map';
    $scss       = '$color: green; .test { color: $color; }';
    $mapContent = ['version' => 3, 'sources' => ['file://' . $inputFile], 'sourcesContent' => [$scss], 'mappings' => '...'];

    $mockProcess = mockProcess('.test{color:green}', $mapContent);
    $mockCompiler = mockCompiler();
    $mockCompiler->shouldReceive('createProcess')->andReturn($mockProcess);
    $mockCompiler->shouldReceive('checkEnvironment')->andReturnNull();
    $mockCompiler->shouldReceive('findNode')->andReturn('node');

    $mockCompiler->compileFileAndSave($inputFile, $outputFile, [
        'sourceMap'      => true,
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

it('compiles and saves file only if source has changed', function () {
    $scss1 = '$color: red; body { color: $color; }';
    $scss2 = '$color: blue; body { color: $color; }';
    $css1  = 'body{color:red}';
    $css2  = 'body{color:blue}';

    $files      = ['test_input.scss' => $scss1];
    $root       = setupVfs($files);
    $inputFile  = $root . '/test_input.scss';
    $outputFile = $root . '/test_output.css';

    touch($inputFile, 1000);

    $mockCompiler = mockCompiler();

    $mockProcess1 = mockProcess($css1);
    $mockProcess1->shouldReceive('isRunning')->andReturn(true);
    $mockProcess2 = mockProcess($css2);
    $mockProcess2->shouldReceive('isRunning')->andReturn(true);

    $mockCompiler->shouldReceive('readFile')->with($inputFile)->andReturn($scss1, $scss2);
    $mockCompiler->shouldReceive('getOrCreateProcess')->andReturn($mockProcess1, $mockProcess2);
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
    $files   = ['tmp.scss' => ''];
    $root    = setupVfs($files);
    $tmpFile = $root . '/tmp.scss';

    $mockCompiler = mockCompiler();
    $mockCompiler->shouldReceive('checkEnvironment')->andReturnNull();
    $mockCompiler->shouldReceive('findNode')->andReturn('node');

    $css = $mockCompiler->compileFile($tmpFile);
    assertCssEquals($css, '');
});

it('returns the options set via setOptions', function () {
    $options = [
        'syntax'         => 'sass',
        'style'          => 'compressed',
        'sourceMap'      => true,
        'includeSources' =>  true,
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

it('detects current operating system in isWindows', function () {
    $isWindows = (fn() => $this->isWindows())->call($this->compiler);

    expect($isWindows)->toBe(PHP_OS_FAMILY === 'Windows');
});

it('throws exception if no node candidate is successful', function () {
    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('run')->atLeast()->andReturn(0);
    $mockProcess->shouldReceive('isSuccessful')->atLeast()->andReturn(false);

    $compiler = mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('isWindows')->andReturn(false);
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    expect(function () use ($compiler) {
        (function () {
            return $this->findNode();
        })->call($compiler);
    })->toThrow(Exception::class, 'Node.js not found');
});

it('throws Exception when bridge.js is missing', function () {
    $bridgePath = __DIR__ . '/nonexistent_bridge.js';
    $compiler   = new Compiler($bridgePath, 'node');
    $compiler->compileString('$color: red;');
})->throws(Exception::class);

it('throws Exception when file does not exist', function () {
    $this->compiler->compileFile(__DIR__ . '/nonexistent.scss');
})->throws(Exception::class);

it('throws Exception when file exists but cannot be read', function () {
    $files = ['unreadable.scss' => '$color: red;'];
    $root  = setupVfs($files);
    $file  = $root . '/unreadable.scss';

    $compiler = mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('readFile')->andReturnFalse();

    $compiler->compileFile($file);
})->throws(Exception::class, 'Unable to read file:');

it('throws Exception when sass process returns empty output', function () {
    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn('');
    $mockProcess->shouldReceive('getErrorOutput')->once()->andReturn('some error details');

    $compiler = mock(Compiler::class)->makePartial();
    $compiler->__construct(nodePath: 'node');
    $compiler->shouldAllowMockingProtectedMethods();

    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    expect(fn() => $compiler->compileString('$color: red;'))
        ->toThrow(Exception::class, 'Sass process failed: some error details');
});

it('throws Exception on invalid response from sass bridge', function () {
    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn('invalid-json');

    $compiler = mock(Compiler::class)->makePartial();
    $compiler->__construct(nodePath: 'node');
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    expect(fn() => $compiler->compileString('$color: red;'))
        ->toThrow(Exception::class, 'Invalid response from sass bridge');
});

it('throws Exception on error response from sass bridge in standard mode', function () {
    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(
        json_encode(['error' => 'Unexpected token'])
    );
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    $compiler = mock(Compiler::class)->makePartial();
    $compiler->__construct(nodePath: 'node');
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    expect(fn() => $compiler->compileString('$color: red;'))
        ->toThrow(Exception::class, 'Sass parsing error: Unexpected token');
});

it('throws Exception if sass-embedded folder does not exist', function () {
    $compiler = mock(Compiler::class)->makePartial();

    $fakePackageRoot = '/non/existent/path';

    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('getPackageRoot')
        ->andReturn($fakePackageRoot);

    $this->expectException(Exception::class);
    $this->expectExceptionMessage("sass-embedded not found. Run `npm install` in $fakePackageRoot.");

    $compiler->__construct(nodePath: 'node');
});

it('throws Exception when input file does not exist in compileFileAndSave', function () {
    $this->compiler->compileFileAndSave(
        __DIR__ . '/nonexistent.scss',
        __DIR__ . '/output.css'
    );
})->throws(Exception::class);

it('processes source map with URL path', function () {
    $scss        = '$color: blue; .box { color: $color; }';
    $mockProcess = mockProcess('body{color:blue}', [
        'version'  => 3,
        'mappings' => '...',
        'sources'  => ['style.scss'],
    ]);

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $css = $compiler->compileString($scss, ['sourceMap' => true, 'sourceMapPath' => 'https://example.com/style.map']);
    expect($css)->toContain('/*# sourceMappingURL=https://example.com/style.map */');
});

it('processes source map with directory path', function () {
    $root = vfsStream::setup();

    vfsStream::newDirectory('test_maps')->at($root);

    $testDir   = vfsStream::url('root/test_maps');
    $inputFile = vfsStream::url('root/test.scss');
    $scss      = '$color: blue; .box { color: $color; }';

    vfsStream::newFile('test.scss')->at($root)->setContent($scss);

    $expectedMapFile = $testDir . '/style.map';

    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(
        json_encode([
            'css'       => 'body{color:blue}',
            'sourceMap' => ['version' => 3, 'mappings' => '...', 'sources' => ['https://example.com/style.scss']],
        ])
    );
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');

    $css = $compiler->compileFile(
        $inputFile,
        [
            'sourceMap'     => true,
            'sourceMapPath' => $testDir,
            'url'           => 'https://example.com/style.scss',
        ]
    );

    expect(file_exists($expectedMapFile))->toBeTrue()
        ->and($css)->toContain('/*# sourceMappingURL=style.map */');
});

it('extracts filename from URL in getSourceFilenameFromUrl', function () {
    $compiler = mock(Compiler::class)->makePartial();
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
    $compiler->shouldReceive('createProcess')->once()->andReturnUsing(
        function () use ($mockProcess, &$processCreated) {
            $processCreated = true;

            return $mockProcess;
        }
    );

    $result1 = $compiler->compileString('$color: red; body { color: $color; }');
    $result2 = $compiler->compileString('$color: red; body { color: $color; }');

    expect($processCreated)->toBeTrue()
        ->and($result1)->toBe($expectedCss)
        ->and($result2)->toBe($expectedCss);
});

it('assembles streamed chunks in compileString', function () {
    $scss   = '$color: red; body { color: $color; }';
    $chunks = ['body{', 'color:red', '}'];

    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(
        json_encode([
            'isStreamed' => true,
            'chunks'     => $chunks,
        ])
    );
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    $compiler = mockCompiler();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $css = $compiler->compileString($scss, ['streamResult' => true]);

    expect($css)->toBe('body{color:red}');
});
it('checks that compileStringAsGenerator returns generator with correct results', function () {
    $scss        = '$color: red; body { color: $color; }';
    $expectedCss = <<<'CSS'
    body {
      color: red;
    }
    CSS;

    compileAndAssertGenerator($scss, $expectedCss);
});

it('handles large files with streaming and generators', function () {
    $scss          = generateLargeScss(1024 * 1024);
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

    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('setInput')->times(5)->andReturnSelf();
    $mockProcess->shouldReceive('run')->times(5)->andReturn(0);
    $expectedMemoryCss = <<<'CSS'
    body {
      color: red;
      font-size: 10px;
    }
    CSS;

    $mockProcess->shouldReceive('getOutput')->andReturn(
        json_encode([
            'css'       => $expectedMemoryCss,
            'sourceMap' => null,
        ])
    );
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
    $scss        = '$color: red; body { color: $color; }';
    $expectedCss = 'body{color:red}';
    $sourceMap   = ['version' => 3, 'mappings' => '...'];

    compileAndAssertGenerator($scss, $expectedCss, ['sourceMap' => true], $sourceMap, true, [$expectedCss]);
});

it('compiles string as generator with sourceMap in non-streamed mode', function () {
    $scss        = '$color: blue; body { color: $color; }';
    $expectedCss = 'body{color:blue}';
    $sourceMap   = ['version' => 3, 'mappings' => '...'];

    compileAndAssertGenerator($scss, $expectedCss, ['sourceMap' => true], $sourceMap);
});

it('resets cached process when not running', function () {
    $scss        = '$color: green; body { color: $color; }';
    $expectedCss = 'body{color:green}';

    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('setInput')->twice()->andReturnSelf();
    $mockProcess->shouldReceive('run')->twice()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->andReturn(json_encode(['css' => $expectedCss]));
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');
    $mockProcess->shouldReceive('isRunning')->andReturn(false, true);

    $compiler = mockCompiler();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('getOrCreateProcess')->andReturn($mockProcess);

    $result1 = $compiler->compileString($scss);
    expect($result1)->toBe($expectedCss);

    $result2 = $compiler->compileString($scss);
    expect($result2)->toBe($expectedCss);
});

it('processes sourceMap with file path adding .map extension', function () {
    $scss            = '$color: yellow; body { color: $color; }';
    $expectedCss     = 'body{color:yellow}';
    $sourceMap       = ['version' => 3, 'mappings' => '...'];
    $mapPath         = vfsStream::url('root/output');
    $expectedMapFile = $mapPath . '.map';

    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(
        json_encode([
            'css'       => $expectedCss,
            'sourceMap' => $sourceMap,
        ])
    );
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    $compiler = mockCompiler();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $css = $compiler->compileString($scss, ['sourceMap' => true, 'sourceMapPath' => $mapPath]);
    expect($css)->toBe($expectedCss . "\n/*# sourceMappingURL=output.map */")
        ->and(file_exists($expectedMapFile))->toBeTrue();
});

it('compiles multiple requests using persistent mode', function () {
    $scss1        = '$color: red; body { color: $color; }';
    $scss2        = '$color: blue; .test { color: $color; }';
    $expectedCss1 = 'body{color:red}';
    $expectedCss2 = '.test{color:blue}';

    $mockProcess = mock(Process::class);
    $mockProcess
        ->shouldReceive('setInput')
        ->once()
        ->with(Mockery::type(InputStream::class))
        ->andReturnSelf();
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('isRunning')->andReturn(true);
    $mockProcess->shouldReceive('getIncrementalOutput')->andReturn(
        json_encode(['css' => $expectedCss1]) . "\n",
        json_encode(['css' => $expectedCss2]) . "\n"
    );
    $mockProcess->shouldReceive('getIncrementalErrorOutput')->andReturn('', '');

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');

    $compiler->enablePersistentMode();

    $result1 = $compiler->compileInPersistentMode($scss1);
    $result2 = $compiler->compileInPersistentMode($scss2);

    expect($result1)->toBe($expectedCss1)
        ->and($result2)->toBe($expectedCss2);
});

it('compiles in persistent mode with options', function () {
    $scss        = '$color: green; .box { color: $color; }';
    $expectedCss = '.box{color:green}';

    $mockProcess = mockPersistentProcess($expectedCss);
    $compiler    = mockPersistentCompiler($mockProcess);
    $compiler->enablePersistentMode();

    $result = $compiler->compileInPersistentMode($scss, ['compressed' => true]);
    expect($result)->toBe($expectedCss);
});

it('exits persistent mode properly', function () {
    $scss        = '$color: red; body { color: $color; }';
    $expectedCss = 'body{color:red}';

    $mockProcess = mockPersistentProcess($expectedCss, true);
    $compiler    = mockPersistentCompiler($mockProcess);
    $compiler->enablePersistentMode();

    $result = $compiler->compileInPersistentMode($scss);
    expect($result)->toBe($expectedCss);

    $compiler->disablePersistentMode();
});

it('throws exception on error in persistent mode', function () {
    $scss         = '$color: red; invalid syntax }';
    $errorMessage = 'Sass parsing error: Invalid syntax';

    $mockProcess = mock(Process::class);
    $mockProcess
        ->shouldReceive('setInput')
        ->once()
        ->with(Mockery::type(InputStream::class))
        ->andReturnSelf();
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('isRunning')->andReturn(true);
    $mockProcess->shouldReceive('getIncrementalOutput')->once()->andReturn(
        json_encode(['error' => $errorMessage]) . "\n"
    );
    $mockProcess->shouldReceive('getIncrementalErrorOutput')->once()->andReturn('');

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->enablePersistentMode();

    expect(fn() => $compiler->compileInPersistentMode($scss))
        ->toThrow(Exception::class, $errorMessage);
});

it('returns empty css for empty string in persistent mode', function () {
    $compiler = new Compiler(nodePath: 'node');
    $compiler->enablePersistentMode();

    $result = $compiler->compileInPersistentMode('');
    expect($result)->toBe('');
});

it('compiles in persistent mode with sourceMap', function () {
    $scss              = '$color: purple; .box { color: $color; }';
    $expectedCss       = '.box{color:purple}';
    $expectedSourceMap = ['version' => 3, 'mappings' => '...'];

    $mockProcess = mock(Process::class);
    $mockProcess
        ->shouldReceive('setInput')
        ->once()
        ->with(Mockery::type(InputStream::class))
        ->andReturnSelf();
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('isRunning')->andReturn(true);
    $mockProcess->shouldReceive('getIncrementalOutput')->once()->andReturn(
        json_encode(['css' => $expectedCss, 'sourceMap' => $expectedSourceMap]) . "\n"
    );
    $mockProcess->shouldReceive('getIncrementalErrorOutput')->once()->andReturn('');

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('processSourceMap')->once()->with($expectedSourceMap, [])->andReturn(
        "\n/*# sourceMappingURL=data:application/json;base64,encoded */"
    );
    $compiler->enablePersistentMode();

    $result = $compiler->compileInPersistentMode($scss);
    expect($result)->toBe($expectedCss . "\n/*# sourceMappingURL=data:application/json;base64,encoded */");
});

it('throws exception when persistent process fails with error output', function () {
    $scss = '$color: red; body { color: $color; }';

    $mockProcess = mock(Process::class);
    $mockProcess
        ->shouldReceive('setInput')
        ->once()
        ->with(Mockery::type(InputStream::class))
        ->andReturnSelf();
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('getIncrementalOutput')->once()->andReturn('');
    $mockProcess->shouldReceive('getIncrementalErrorOutput')->once()->andReturn('Compilation failed: syntax error');
    $mockProcess->shouldReceive('isRunning')->once()->andReturn(false);

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->enablePersistentMode();

    expect(fn() => $compiler->compileInPersistentMode($scss))
        ->toThrow(
            Exception::class,
            'Sass persistent process failed: Compilation failed: syntax error'
        );
});

it('throws exception when persistent process returns invalid json', function () {
    $scss = '$color: red; body { color: $color; }';

    $mockProcess = mock(Process::class);
    $mockProcess
        ->shouldReceive('setInput')
        ->once()
        ->with(Mockery::type(InputStream::class))
        ->andReturnSelf();
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('isRunning')->andReturn(true);
    $mockProcess->shouldReceive('getIncrementalOutput')->once()->andReturn('invalid json' . "\n");
    $mockProcess->shouldReceive('getIncrementalErrorOutput')->once()->andReturn('');

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->enablePersistentMode();

    expect(fn() => $compiler->compileInPersistentMode($scss))
        ->toThrow(Exception::class, 'Invalid response from sass persistent bridge');
});

it('skips empty persistent output lines before JSON response', function () {
    $scss        = '$color: red; body { color: $color; }';
    $expectedCss = 'body{color:red}';

    $mockProcess = mock(Process::class);
    $mockProcess
        ->shouldReceive('setInput')
        ->once()
        ->with(Mockery::type(InputStream::class))
        ->andReturnSelf();
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('getIncrementalOutput')->andReturn(
        "\n",
        json_encode(['css' => $expectedCss]) . "\n"
    );
    $mockProcess->shouldReceive('getIncrementalErrorOutput')->andReturn('', '');

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->enablePersistentMode();

    $result = $compiler->compileInPersistentMode($scss);
    expect($result)->toBe($expectedCss);
});

it('throws exception when persistent response times out', function () {
    $scss = '$color: red; body { color: $color; }';

    TimeOverrides::$enabled = true;
    TimeOverrides::$values  = [1000.0, 1031.0];

    try {
        $mockProcess = mock(Process::class);
        $mockProcess->shouldReceive('setInput')->once()->with(Mockery::type(InputStream::class))->andReturnSelf();
        $mockProcess->shouldReceive('start')->once();
        $mockProcess->shouldReceive('getIncrementalOutput')->once()->andReturn('');
        $mockProcess->shouldReceive('getIncrementalErrorOutput')->once()->andReturn('');
        $mockProcess->shouldReceive('isRunning')->once()->andReturn(true);

        $compiler = mockCompiler();
        $compiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);
        $compiler->shouldReceive('checkEnvironment')->andReturnNull();
        $compiler->shouldReceive('findNode')->andReturn('node');
        $compiler->enablePersistentMode();

        expect(fn() => $compiler->compileInPersistentMode($scss))
            ->toThrow(Exception::class, 'Sass persistent process failed: timed out while waiting for response');
    } finally {
        TimeOverrides::$enabled = false;
        TimeOverrides::$values  = [];
    }
});

it('resets cached process and command when process is not running', function () {
    $cmd = ['node', '--version'];

    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('isRunning')->andReturn(false);

    $reflection = new ReflectionClass(Compiler::class);
    $reflection->setStaticPropertyValue('cachedProcess', $mockProcess);
    $reflection->setStaticPropertyValue('cachedCommand', $cmd);

    $newMockProcess = mock(Process::class);
    $newMockProcess->shouldReceive('isRunning')->andReturn(true);

    $compiler = mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('createProcess')->once()->with($cmd)->andReturn($newMockProcess);

    $result = $compiler->mockery_callSubjectMethod('getOrCreateProcess', [$cmd]);

    expect($result)->toBe($newMockProcess)
        ->and($reflection->getStaticPropertyValue('cachedProcess'))->toBe($newMockProcess)
        ->and($reflection->getStaticPropertyValue('cachedCommand'))->toBe($cmd);
});

it('assembles streamed sourceMap chunks', function () {
    $scss          = '$color: orange; body { color: $color; }';
    $expectedCss   = 'body{color:orange}';
    $sourceMap     = ['version' => 3, 'mappings' => 'AAAA', 'sources' => ['input.scss']];
    $sourceMapJson = json_encode($sourceMap);
    $chunkSize     = 10;

    $chunks = [];
    for ($i = 0; $i < strlen($sourceMapJson); $i += $chunkSize) {
        $chunks[] = substr($sourceMapJson, $i, $chunkSize);
    }

    $mockProcess = mock(Process::class);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(
        json_encode([
            'css'                 => $expectedCss,
            'sourceMapChunks'     => $chunks,
            'sourceMapIsStreamed' => true,
        ])
    );
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    $compiler = mockCompiler();
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');
    $compiler->shouldReceive('processSourceMap')->once()->with($sourceMap, ['sourceMap' => true])->andReturn(
        "\n/*# sourceMappingURL=data:application/json;base64,encoded */"
    );

    $result = $compiler->compileString($scss, ['sourceMap' => true]);

    expect($result)->toBe($expectedCss . "\n/*# sourceMappingURL=data:application/json;base64,encoded */");
});
