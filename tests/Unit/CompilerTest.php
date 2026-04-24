<?php

declare(strict_types=1);

use Bugo\Sass\Compiler;
use Bugo\Sass\Exception;
use Bugo\Sass\Options;

beforeEach(function () {
    $this->compiler = new Compiler();
});

it('returns empty css for empty string input in compileString', function () {
    $css = $this->compiler->compileString('');

    expect($css)->toBe('');
});

it('returns the options set via setOptions as Options object', function () {
    $this->compiler->setOptions(new Options(
        syntax: 'sass',
        style: 'compressed',
        sourceMapPath: '/tmp/style.map',
    ));

    $options = $this->compiler->getOptions();

    expect($options)->toBeInstanceOf(Options::class)
        ->and($options->syntax)->toBe('sass')
        ->and($options->style)->toBe('compressed')
        ->and($options->sourceMapPath)->toBe('/tmp/style.map');
});

it('setOptions with Options object stores it directly', function () {
    $options = new Options(
        syntax: 'sass',
        style: 'compressed',
        includeSources: true,
        sourceMapPath: '/tmp/style.map',
    );

    $this->compiler->setOptions($options);

    $stored = $this->compiler->getOptions();

    expect($stored)->toBeInstanceOf(Options::class)
        ->and($stored->syntax)->toBe('sass')
        ->and($stored->style)->toBe('compressed')
        ->and($stored->includeSources)->toBeTrue()
        ->and($stored->sourceMapPath)->toBe('/tmp/style.map');
});

it('setOptions with Options object does not trigger a deprecation notice', function () {
    $deprecation = null;
    set_error_handler(function (int $errno, string $errstr) use (&$deprecation): bool {
        $deprecation = $errstr;

        return true;
    }, E_USER_DEPRECATED);

    $this->compiler->setOptions(new Options(style: 'compressed'));

    restore_error_handler();

    expect($deprecation)->toBeNull();
});

it('method-level Options override compiler defaults', function () {
    $this->compiler->setOptions(new Options(
        style: 'expanded',
        includeSources: true,
    ));

    $resolved = (fn() => array_merge(
        $this->resolveOptions(),
        $this->resolveOptions(new Options(style: 'compressed'))
    ))->call($this->compiler);

    expect($resolved)->toBe([
        'style' => 'compressed',
        'includeSources' => true,
    ]);
});

it('throws Exception when file does not exist', function () {
    $this->compiler->compileFile(__DIR__ . '/nonexistent.scss');
})->throws(Exception::class);

it('throws Exception when input file does not exist in compileFileAndSave', function () {
    $this->compiler->compileFileAndSave(
        __DIR__ . '/nonexistent.scss',
        __DIR__ . '/output.css'
    );
})->throws(Exception::class);

it('compiles an existing file with default options', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'scss') . '.scss';

    file_put_contents($tmpFile, '$color: red; .box { color: $color; }');

    $compiler = new class () extends Compiler {
        protected function compileFileNative(string $filePath, array $options): string
        {
            return json_encode(['file' => $filePath, 'options' => $options]);
        }
    };

    $result = $compiler->compileFile($tmpFile);
    $data   = json_decode($result, true);

    expect($data['file'])->toBe($tmpFile)
        ->and($data['options'])->toBe([]);

    unlink($tmpFile);
});

it('compiles an existing file with merged options', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'scss') . '.scss';

    file_put_contents($tmpFile, '$color: red; .box { color: $color; }');

    $compiler = new class () extends Compiler {
        protected function compileFileNative(string $filePath, array $options): string
        {
            return json_encode(['file' => $filePath, 'options' => $options]);
        }
    };

    $compiler->setOptions(new Options(style: 'expanded'));

    $result = $compiler->compileFile($tmpFile, new Options(style: 'compressed'));
    $data   = json_decode($result, true);

    expect($data['file'])->toBe($tmpFile)
        ->and($data['options'])->toBe(['style' => 'compressed']);

    unlink($tmpFile);
});

it('throws Exception on invalid scss syntax in compileString', function () {
    expect(fn() => $this->compiler->compileString('{'))
        ->toThrow(Exception::class, 'Sass compilation error:');
});

it('throws Exception when compileString output is empty', function () {
    expect(fn() => $this->compiler->compileString('// comment'))
        ->toThrow(Exception::class, 'Sass process failed: unknown error');
});

it('compileFileAndSave writes css when input is newer than output', function () {
    $input  = tempnam(sys_get_temp_dir(), 'scss') . '.scss';
    $output = tempnam(sys_get_temp_dir(), 'css') . '.css';

    file_put_contents($input, '.box { color: red; }');
    touch($output, time() - 100);

    $result = $this->compiler->compileFileAndSave($input, $output);

    expect($result)->toBeTrue()
        ->and(file_get_contents($output))->toContain('.box');

    unlink($input);
    unlink($output);
});

it('compileFileAndSave returns false when output is up to date', function () {
    $input  = tempnam(sys_get_temp_dir(), 'scss') . '.scss';
    $output = tempnam(sys_get_temp_dir(), 'css') . '.css';

    file_put_contents($input, '.box { color: red; }');
    touch($output, time() + 100);

    $result = $this->compiler->compileFileAndSave($input, $output);

    expect($result)->toBeFalse();

    unlink($input);
    unlink($output);
});

it('throws Exception when compileFile receives invalid scss', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'scss') . '.scss';
    file_put_contents($tmpFile, '{');

    expect(fn() => $this->compiler->compileFile($tmpFile))
        ->toThrow(Exception::class, 'Sass compilation error:');

    unlink($tmpFile);
});

it('compileFile includes inline source map when requested', function () {
    $input = tempnam(sys_get_temp_dir(), 'scss') . '.scss';

    file_put_contents($input, '.box { color: red; }');

    $css = $this->compiler->compileFile($input, new Options(
        sourceMapPath: 'inline',
        url: 'file:///virtual/input.scss',
    ));

    expect($css)->toContain('sourceMappingURL=data:application/json;base64,');

    unlink($input);
});

it('compileFile uses loadPaths option', function () {
    $input = tempnam(sys_get_temp_dir(), 'scss') . '.scss';
    $loadPath = sys_get_temp_dir();
    $importFile = $loadPath . '/_test.scss';

    file_put_contents($importFile, '.imported { color: blue; }');
    file_put_contents($input, '@use "test";');

    $css = $this->compiler->compileFile($input, new Options(
        loadPaths: [$loadPath]
    ));

    expect($css)->toContain('.imported');

    unlink($input);
    unlink($importFile);
});

it('compileFile uses quietDeps option', function () {
    $input = tempnam(sys_get_temp_dir(), 'scss') . '.scss';

    file_put_contents($input, '.box { color: red; }');

    $css = $this->compiler->compileFile($input, new Options(
        quietDeps: true
    ));

    expect($css)->toContain('.box');

    unlink($input);
});

it('compileFile uses silenceDeprecations option', function () {
    $input = tempnam(sys_get_temp_dir(), 'scss') . '.scss';

    file_put_contents($input, '.box { color: red; }');

    $css = $this->compiler->compileFile($input, new Options(
        silenceDeprecations: ['import']
    ));

    expect($css)->toContain('.box');

    unlink($input);
});

it('getSassCommand returns correct path for current platform', function () {
    $input = tempnam(sys_get_temp_dir(), 'scss') . '.scss';

    file_put_contents($input, '.box { color: red; }');

    $css = $this->compiler->compileFile($input);

    expect($css)->toContain('.box');

    unlink($input);
});

it('getSassCommand returns Windows dart+snapshot paths when isWindows returns true', function () {
    $compiler = new class () extends Compiler {
        protected function isWindows(): bool
        {
            return true;
        }

        public function exposeSassCommand(): array
        {
            return $this->getSassCommand();
        }
    };

    $binDir  = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin';
    $command = $compiler->exposeSassCommand();

    expect($command)->toBe([
        $binDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'dart.exe',
        $binDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'sass.snapshot',
    ]);
});

it('getSassCommand returns unix sass path when isWindows returns false', function () {
    $compiler = new class () extends Compiler {
        protected function isWindows(): bool
        {
            return false;
        }

        public function exposeSassCommand(): array
        {
            return $this->getSassCommand();
        }
    };

    $binDir  = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin';
    $command = $compiler->exposeSassCommand();

    expect($command)->toBe([
        $binDir . DIRECTORY_SEPARATOR . 'sass',
    ]);
});
