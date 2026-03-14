# sass-embedded-php

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/sass-embedded-php/badge.svg?branch=main)](https://coveralls.io/github/dragomano/sass-embedded-php?branch=main)

[English](README.md)

PHP-обёртка для [sass-embedded](https://npmx.dev/package/sass-embedded) (Dart Sass через Node.js).

Позволяет компилировать SCSS/SASS в CSS через PHP, используя Node.js и npm.

---

## Требования

- PHP >= 8.2
- Node.js >= 18

---

## Установка через Composer

```bash
composer require bugo/sass-embedded-php
```

## Примеры использования

### Компиляция файла SCSS

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;

$compiler = new Compiler();

$css = $compiler->compileFile(__DIR__ . '/assets/app.scss');

file_put_contents(__DIR__ . '/assets/app.css', $css);

echo "CSS скомпилирован!\n";
```

### Компиляция SCSS из строки

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;

$compiler = new Compiler();

$scss = '$color: red; body { color: $color; }';
$css = $compiler->compileString($scss);

echo $css;
```

### Компиляция из строки с опциями

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
    sourceMap: true,
));

$css = $compiler->compileString($scss);

echo $css;
```

### Перехват ошибок при компиляции

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
    echo "Ошибка компиляции: " . $e->getMessage();
}
```

### Компиляция файла SCSS с проверкой изменений

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
        echo "CSS перекомпилирован и сохранен.\n";
    } else {
        echo "Изменений не обнаружено, компиляция пропущена.\n";
    }
} catch (Exception $e) {
    echo "Ошибка компиляции: " . $e->getMessage();
}
```

Этот метод автоматически проверяет, был ли изменён исходный файл с момента последней компиляции, и компилирует и сохраняет только если обнаружены изменения.

### Компиляция файла с картами источников и сжатым выводом

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;
use Bugo\Sass\Options;

$compiler = new Compiler();

$compiler->setOptions(new Options(
    style: 'compressed',
    sourceMap: true,
    includeSources: true,
    sourceMapPath: __DIR__ . '/assets/',
));

$css = $compiler->compileFile(__DIR__ . '/assets/app.scss');

file_put_contents(__DIR__ . '/assets/app.css', $css);

echo "CSS скомпилирован с картой источников!\n";
```

## Параметры

Пути к bridge.js и Node указываются только через конструктор:

```php
$compiler = new Compiler('/path/to/bridge.js', '/path/to/node');
```

| Параметр       | Тип    | Описание                                                    | Возможные значения                              |
|----------------|--------|-------------------------------------------------------------|-------------------------------------------------|
| syntax         | string | Синтаксис входного файла                                    | 'scss' для SCSS, 'indented' или 'sass' для SASS |
| style          | string | Стиль вывода                                                | 'compressed' или 'expanded'                     |
| sourceMap      | bool   | Генерировать карту источников                               | true или false                                  |
| includeSources | bool   | Включать исходный код в карту                               | true или false                                  |
| sourceMapPath  | string | URL-адрес уже созданной карты или путь для сохранения новой |                                                 |

Параметры можно включать как для всего компилятора сразу, так и для конкретного метода отдельно:

```php
use Bugo\Sass\Options;

$compiler->setOptions(new Options(
    syntax: 'indented',
    style: 'compressed',
    sourceMap: true,
));
```

## Расширенные опции

Эти опции позволяют контролировать дополнительные аспекты компиляции Sass.

| Опция               | Тип           | Описание                                              | Пример использования            |
|---------------------|---------------|-------------------------------------------------------|---------------------------------|
| loadPaths           | array<string> | Массив путей для поиска импортов Sass                 | `['./libs', './node_modules']`  |
| quietDeps           | bool          | Подавляет предупреждения от зависимостей              | `true`                          |
| silenceDeprecations | array<string> | Подавляет предупреждения об определённых устареваниях | `['import', 'color-functions']` |
| verbose             | bool          | Включает подробный вывод сообщений                    | `true`                          |

```php
use Bugo\Sass\Options;

$compiler->setOptions(new Options(
    loadPaths: ['/path/to/custom/libs'],
    quietDeps: true,
    silenceDeprecations: ['import'],
    verbose: true,
));
```
