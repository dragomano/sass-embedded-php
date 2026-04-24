# sass-embedded-php

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/sass-embedded-php/badge.svg?branch=main)](https://coveralls.io/github/dragomano/sass-embedded-php?branch=main)

[По-русски](README.ru.md)

Allows compiling SCSS/SASS to CSS through PHP using native Dart Sass.

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
use Bugo\Sass\Options;

$compiler = new Compiler();

$scss = <<<'SCSS'
$color: #3498db;
$font-size: 14px;

body {
  font-size: $font-size;
  color: $color;
}
SCSS;

$compiler->setOptions(new Options(
    style: 'compressed',
    sourceMapPath: 'inline',
));

$css = $compiler->compileString($scss);

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
        __DIR__ . '/assets/style.css',
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
use Bugo\Sass\Options;

$compiler = new Compiler();

$compiler->setOptions(new Options(
    style: 'compressed',
    includeSources: true,
    sourceMapPath: __DIR__ . '/assets/',
));

$css = $compiler->compileFile(__DIR__ . '/assets/app.scss');

file_put_contents(__DIR__ . '/assets/app.css', $css);

echo "CSS compiled with source map!\n";
```

### Compiling string with inline source map

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;
use Bugo\Sass\Options;

$compiler = new Compiler();

$compiler->setOptions(new Options(
    sourceMapPath: 'inline',
    includeSources: true,
));

$css = $compiler->compileString('$color: red; body { color: $color; }');

echo $css;
```

## Options

The compiler uses the native Dart Sass binary installed into the package automatically:

```php
$compiler = new Compiler();
```

| Option              | Type          | Description                                                | Possible values                                | Default                                             |
|---------------------|---------------|------------------------------------------------------------|------------------------------------------------|-----------------------------------------------------|
| syntax              | string        | Input syntax                                               | 'scss' for SCSS, 'indented' or 'sass' for SASS | `scss`                                              |
| style               | string        | Output style                                               | 'compressed' or 'expanded'                     | `expanded`                                          |
| includeSources      | bool          | Include source code in map                                 | true or false                                  | `false`                                             |
| loadPaths           | array<string> | Array of paths for searching Sass imports                  | `['./libs', './node_modules']`                 | `[]`                                                |
| quietDeps           | bool          | Suppresses warnings from dependencies                      | true or false                                  | `false`                                             |
| silenceDeprecations | array<string> | Suppresses warnings about specific deprecations            | `['import', 'color-functions']`                | `[]`                                                |
| verbose             | bool          | Enables verbose output of messages                         | true or false                                  | `false`                                             |
| sourceMapPath       | string        | `inline`, URL, directory, or file path for source map      |                                                | disabled                                            |
| url                 | string        | Sets the source URL used by Sass and source maps           | file or HTTP(S) URL                            | auto in `compileFile()`, unset in `compileString()` |
| sourceFile          | string        | Sets the virtual source filename for bridge processing     | e.g. `style.scss`                              | internal bridge default                             |

Options are passed only as an `Options` object, either for the entire compiler or for a specific method call:

```php
use Bugo\Sass\Options;

$compiler->setOptions(new Options(
    syntax: 'indented',
    style: 'compressed',
    sourceMapPath: '/out/style.map',
));

$css = $compiler->compileString($scss, new Options(
    style: 'compressed',
    sourceMapPath: 'inline',
));
```

All available options in `Options` default to `null`, which means the option was not explicitly set. The `Default` column describes the effective compiler behavior in that case.
