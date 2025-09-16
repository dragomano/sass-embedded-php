<?php declare(strict_types=1);

use Bugo\Sass\CompilerInterface;
use Bugo\Sass\Compiler;
use Mockery\Mock;
use Mockery\MockInterface;
use org\bovigo\vfs\vfsStream;
use Symfony\Component\Process\Process;

function setupCompilerPaths(CompilerInterface $compiler): void
{
    $reflection = new ReflectionClass($compiler);

    try {
        $reflection->getProperty('nodePath')->setValue($compiler, 'node');
        $reflection->getProperty('bridgePath')->setValue($compiler, __DIR__ . '/../bin/bridge.js');
    } catch (ReflectionException) {}
}

function mockProcess(string $expectedCss, ?array $expectedSourceMap = null): Mock|(MockInterface&Process)
{
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('setInput')->andReturnSelf();
    $mockProcess->shouldReceive('run')->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->andReturn(json_encode([
        'css' => $expectedCss, 'sourceMap' => $expectedSourceMap
    ]));
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    return $mockProcess;
}

function mockCompiler(): Mock|(MockInterface&Compiler)
{
    $mockCompiler = Mockery::mock(Compiler::class)->makePartial();
    $mockCompiler->shouldAllowMockingProtectedMethods();

    setupCompilerPaths($mockCompiler);

    return $mockCompiler;
}

function assertCssEquals(string $actual, string $expected): void
{
    expect($actual)->toBe($expected);
}

function setupVfs(array $files = []): string
{
    $root = vfsStream::setup();

    foreach ($files as $filename => $content) {
        vfsStream::newFile($filename)->at($root)->setContent($content);
    }

    return vfsStream::url('root');
}

function generateLargeScss(int $size): string
{
    $largeVariable = str_repeat('a', $size);
    return '$large: "' . $largeVariable . '"; body::after { content: $large; }';
}

function mockProcessForGenerator(
    string $scss,
    string $expectedCss,
    ?array $expectedSourceMap = null,
    bool $isStreamed = false,
    array $chunks = []
): Process
{
    $mockProcess = Mockery::mock(Process::class);

    if (trim($scss) !== '') {
        $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
        $mockProcess->shouldReceive('run')->once()->andReturn(0);
        $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode([
            'css' => $expectedCss,
            'sourceMap' => $expectedSourceMap,
            'isStreamed' => $isStreamed,
            'chunks' => $chunks ?: [$expectedCss]
        ]));
    }

    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    return $mockProcess;
}

function compileAndAssertGenerator(
    string $scss,
    string $expectedResult,
    array $options = [],
    ?array $expectedSourceMap = null,
    bool $isStreamed = false,
    array $chunks = []
): void
{
    $mockProcess = mockProcessForGenerator($scss, $expectedResult, $expectedSourceMap, $isStreamed, $chunks);

    $compiler = mockCompiler();
    $compiler->shouldReceive('checkEnvironment')->andReturnNull();
    $compiler->shouldReceive('findNode')->andReturn('node');

    if (trim($scss) !== '') {
        $compiler->shouldReceive('createProcess')->andReturn($mockProcess);
    }

    $generator = $compiler->compileStringAsGenerator($scss, $options);
    expect($generator)->toBeInstanceOf(Generator::class);

    $result = '';
    $hasSourceMap = false;
    foreach ($generator as $chunk) {
        if (str_contains($chunk, 'sourceMappingURL')) {
            $hasSourceMap = true;
        } else {
            $result .= $chunk;
        }
    }

    expect($result)->toBe($expectedResult);
    if ($expectedSourceMap !== null) {
        expect($hasSourceMap)->toBeTrue();
    }
}

function assertFindNodeReturnsPath(bool $isWindows): void
{
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('isSuccessful')->once()->andReturn(true);

    $compiler = Mockery::mock(Compiler::class)->makePartial();
    $compiler->shouldAllowMockingProtectedMethods();
    $compiler->shouldReceive('isWindows')->andReturn($isWindows);
    $compiler->shouldReceive('createProcess')->andReturn($mockProcess);

    $node = (fn() => $this->findNode())->call($compiler);

    expect($node)->toBeString();
}

function mockPersistentProcess(string $expectedCss, bool $withExit = false): Mock|(MockInterface&Process)
{
    $mockProcess = Mockery::mock(Process::class);
    $mockProcess->shouldReceive('start')->once();
    $mockProcess->shouldReceive('isRunning')->andReturn(true);
    $mockProcess->shouldReceive('setInput')->once()->andReturnSelf();
    $mockProcess->shouldReceive('run')->once()->andReturn(0);
    $mockProcess->shouldReceive('getOutput')->once()->andReturn(json_encode(['css' => $expectedCss]) . "\n");
    $mockProcess->shouldReceive('getErrorOutput')->andReturn('');

    if ($withExit) {
        $mockProcess->shouldReceive('setInput')->once()->with(json_encode(['exit' => true]) . "\n")->andReturnSelf();
        $mockProcess->shouldReceive('run')->once()->andReturn(0);
        $mockProcess->shouldReceive('stop')->once();
    }

    return $mockProcess;
}

function mockPersistentCompiler(Mock|(MockInterface&Process) $mockProcess): Mock|(MockInterface&Compiler)
{
    $mockCompiler = mockCompiler();
    $mockCompiler->shouldReceive('createProcess')->once()->andReturn($mockProcess);
    $mockCompiler->shouldReceive('checkEnvironment')->andReturnNull();
    $mockCompiler->shouldReceive('findNode')->andReturn('node');

    return $mockCompiler;
}
