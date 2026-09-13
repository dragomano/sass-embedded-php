<?php

declare(strict_types=1);

use Bugo\Sass\Options;

it('has null properties when constructed with no arguments', function () {
    $options = new Options();

    expect($options->syntax)
        ->toBeNull()
        ->and($options->style)
        ->toBeNull()
        ->and($options->includeSources)
        ->toBeNull()
        ->and($options->loadPaths)
        ->toBeNull()
        ->and($options->quietDeps)
        ->toBeNull()
        ->and($options->silenceDeprecations)
        ->toBeNull()
        ->and($options->verbose)
        ->toBeNull()
        ->and($options->sourceMapPath)
        ->toBeNull()
        ->and($options->url)
        ->toBeNull()
        ->and($options->sourceFile)
        ->toBeNull();
});

it('stores syntax via constructor', function () {
    expect((new Options(syntax: 'sass'))->syntax)->toBe('sass');
});

it('stores style via constructor', function () {
    expect((new Options(style: 'compressed'))->style)->toBe('compressed');
});

it('stores includeSources via constructor', function () {
    expect((new Options(includeSources: true))->includeSources)->toBeTrue();
});

it('stores loadPaths via constructor', function () {
    expect((new Options(loadPaths: ['/path/a', '/path/b']))->loadPaths)->toBe(['/path/a', '/path/b']);
});

it('stores quietDeps via constructor', function () {
    expect((new Options(quietDeps: true))->quietDeps)->toBeTrue();
});

it('stores silenceDeprecations via constructor', function () {
    expect((new Options(silenceDeprecations: ['import', 'color-functions']))->silenceDeprecations)
        ->toBe(['import', 'color-functions']);
});

it('stores verbose via constructor', function () {
    expect((new Options(verbose: true))->verbose)->toBeTrue();
});

it('stores sourceMapPath via constructor', function () {
    expect((new Options(sourceMapPath: '/var/www/style.css.map'))->sourceMapPath)
        ->toBe('/var/www/style.css.map');
});

it('stores url via constructor', function () {
    expect((new Options(url: 'file:///var/www/style.scss'))->url)->toBe('file:///var/www/style.scss');
});

it('stores sourceFile via constructor', function () {
    expect((new Options(sourceFile: 'style.scss'))->sourceFile)->toBe('style.scss');
});

it('stores all options via constructor', function () {
    $options = new Options(
        syntax: 'scss',
        style: 'compressed',
        includeSources: false,
        loadPaths: ['/vendor/sass'],
        quietDeps: true,
        silenceDeprecations: ['import'],
        verbose: false,
        sourceMapPath: '/out/style.map',
        url: 'file:///src/style.scss',
        sourceFile: 'style.scss',
    );

    expect($options->syntax)
        ->toBe('scss')
        ->and($options->style)
        ->toBe('compressed')
        ->and($options->includeSources)
        ->toBeFalse()
        ->and($options->loadPaths)
        ->toBe(['/vendor/sass'])
        ->and($options->quietDeps)
        ->toBeTrue()
        ->and($options->silenceDeprecations)
        ->toBe(['import'])
        ->and($options->verbose)
        ->toBeFalse()
        ->and($options->sourceMapPath)
        ->toBe('/out/style.map')
        ->and($options->url)
        ->toBe('file:///src/style.scss')
        ->and($options->sourceFile)
        ->toBe('style.scss');
});

it('returns itself when no overrides are provided', function () {
    $options = new Options(style: 'expanded');

    expect($options->withOverrides(null))->toBe($options);
});

it('inherits null values and preserves explicit false and empty arrays', function () {
    $defaults = new Options(
        syntax: 'scss',
        style: 'expanded',
        includeSources: true,
        loadPaths: ['/default'],
        quietDeps: true,
        silenceDeprecations: ['import'],
        verbose: true,
        sourceMapPath: 'inline',
        url: 'file:///default.scss',
        sourceFile: 'default.scss',
    );

    $merged = $defaults->withOverrides(new Options(
        style: 'compressed',
        includeSources: false,
        loadPaths: [],
        quietDeps: false,
        silenceDeprecations: [],
        verbose: false,
        url: 'file:///override.scss',
    ));

    expect($merged->syntax)
        ->toBe('scss')
        ->and($merged->style)
        ->toBe('compressed')
        ->and($merged->includeSources)
        ->toBeFalse()
        ->and($merged->loadPaths)
        ->toBe([])
        ->and($merged->quietDeps)
        ->toBeFalse()
        ->and($merged->silenceDeprecations)
        ->toBe([])
        ->and($merged->verbose)
        ->toBeFalse()
        ->and($merged->sourceMapPath)
        ->toBe('inline')
        ->and($merged->url)
        ->toBe('file:///override.scss')
        ->and($merged->sourceFile)
        ->toBe('default.scss');
});
