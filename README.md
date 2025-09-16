# sass-embedded-php

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/sass-embedded-php/badge.svg?branch=main)](https://coveralls.io/github/dragomano/sass-embedded-php?branch=main)

[По-русски](README.ru.md)

> Created for research purposes, not for use on production sites!

PHP wrapper for [sass-embedded](https://www.npmjs.com/package/sass-embedded) (Dart Sass via Node.js).

Allows compiling SCSS/SASS to CSS through PHP using Node.js and npm.

---

## Requirements

- PHP >= 8.2
- Node.js >= 18

---

## Installation via Composer

```bash
composer require bugo/sass-embedded-php
```

## Usage examples

### Compiling SCSS file

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;

$compiler = new Compiler();

$css = $compiler->compileFile(__DIR__ . '/assets/app.scss');

file_put_contents(__DIR__ . '/assets/app.css', $css);

echo "CSS compiled!\n";
```

### Compiling SCSS from string

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;

$compiler = new Compiler();

$scss = '$color: red; body { color: $color; }';
$css = $compiler->compileString($scss);

echo $css;
```

### Compiling string with options

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;

$compiler = new Compiler();

$scss = <<<'SCSS'
$color: #3498db;
$font-size: 14px;

body {
  font-size: $font-size;
  color: $color;
}
SCSS;

$css = $compiler->compileString($scss, [
    'compressed' => true,
    'sourceMap' => true
]);

echo $css;
```

### Catching compilation errors

```php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Exception;
use Bugo\Sass\Compiler;

$compiler = new Compiler();

$scss = <<<'SCSS'
$color: #e74c3c;
.foo { color: $color; }
SCSS;

try {
    echo $compiler->compileString($scss);
} catch (Exception $e) {
    echo "Compilation error: " . $e->getMessage();
}
```

### Compiling SCSS file with change checking

```php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Exception;
use Bugo\Sass\Compiler;

$compiler = new Compiler();

try {
    $done = $compiler->compileFileAndSave(
        __DIR__ . '/assets/style.scss',
        __DIR__ . '/assets/style.css'
    );

    if ($done) {
        echo "CSS recompiled and saved.\n";
    } else {
        echo "No changes detected, skipped compilation.\n";
    }
} catch (Exception $e) {
    echo "Compilation error: " . $e->getMessage();
}
```

This method automatically checks if the source file has been modified since the last compilation and only compiles and saves if changes are detected.

### Compiling file with source maps and compressed output

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;

$compiler = new Compiler();

$css = $compiler->compileFile(__DIR__ . '/assets/app.scss', [
    'sourceMap' => true,
    'includeSources' => true,
    'sourceMapPath' => __DIR__ . '/assets/',
    'style' => 'compressed',
]);

file_put_contents(__DIR__ . '/assets/app.css', $css);

echo "CSS compiled with source map!\n";
```

## Options

Paths to bridge.js and Node are specified only through the constructor:

```php
$compiler = new Compiler('/path/to/bridge.js', '/path/to/node');
```

| Option | Type | Description | Possible values |
|--------|------|-------------|-----------------|
| syntax | string | Input syntax | 'scss' for SCSS, 'indented' or 'sass' for SASS |
| style | string | Output style | 'compressed' or 'expanded' |
| sourceMap | bool | Generate source map | true or false |
| includeSources | bool | Include source code in map | true or false |
| sourceMapPath | string | URL to existing map or path for saving new |  |

Options can be set either for the entire compiler at once or for a specific method separately:

```php
$compiler->setOptions([
    'syntax' => 'indented',
    'minimize' => true,
    'sourceMap' => true,
]);
```

## Advanced options

These options allow controlling additional aspects of Sass compilation.

| Option | Type | Description | Usage example |
|--------|------|-------------|---------------------|
| loadPaths | array<string> | Array of paths for searching Sass imports | `['./libs', './node_modules']` |
| quietDeps | bool | Suppresses warnings from dependencies | `true` |
| silenceDeprecations | bool | Suppresses warnings about deprecated functions | `true` |
| verbose | bool | Enables verbose output of messages | `true` |

```php
$compiler->setOptions([
    'loadPaths' => ['/path/to/custom/libs'],
    'quietDeps' => true,
    'silenceDeprecations' => true,
    'verbose' => true,
]);
```

## Benchmarks

See the [benchmark.md](benchmark.md) file for performance benchmarks.