# sass-embedded-php

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/sass-embedded-php/badge.svg?branch=main)](https://coveralls.io/github/dragomano/sass-embedded-php?branch=main)

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

### Safe compilation

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
    $changed = $compiler->compileFileAndSave(
        __DIR__ . '/assets/style.scss',
        __DIR__ . '/assets/style.css'
    );

    if ($changed) {
        echo "CSS recompiled and saved.\n";
    } else {
        echo "No changes detected, skipped compilation.\n";
    }
} catch (Exception $e) {
    echo "Compilation error: " . $e->getMessage();
}
```

This method automatically checks if the source file has been modified since the last compilation and only compiles and saves if changes are detected.

## Parameters

Paths to bridge.js and Node are specified only through the constructor:

```php
$compiler = new Compiler('/path/to/bridge.js', '/path/to/node');
```

Other parameters can be set either for the entire compiler at once or for a specific method separately:

```php
$compiler->setOptions([
    'syntax' => 'sass', // 'scss' | 'sass'
    'compressed' => true, // false | true
]);
````
```php
$compiler->compileString($string, ['syntax' => 'sass']);
$compiler->compileFile($file, ['compressed' => true]);
```
