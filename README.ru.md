# sass-embedded-php

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/sass-embedded-php/badge.svg?branch=main)](https://coveralls.io/github/dragomano/sass-embedded-php?branch=main)

[English](README.md)

Компиляция SCSS/SASS в CSS из PHP с помощью нативного Dart Sass. Пакет предоставляет компилятор с повторно используемым embedded-процессом и простой CLI-компилятор с единым контрактом компиляции.

---

## Установка

```bash
composer require bugo/sass-embedded-php
```

Подходящий нативный бинарник Dart Sass автоматически устанавливается в пакет.

## Выбор компилятора

| Сценарий | Рекомендуемая реализация |
|---|---|
| Повторная компиляция, queue worker, daemon или batch | `EmbeddedCompiler` |
| Одноразовый скрипт с простейшим жизненным циклом | `Compiler` |
| Существующая интеграция с CLI-реализацией | `Compiler` |
| Максимальная производительность при повторном использовании процесса | `EmbeddedCompiler` |

Оба класса реализуют `CompilerInterface` и предоставляют одинаковые методы настройки и компиляции. `Compiler` запускает процесс Dart Sass CLI для каждой компиляции. `EmbeddedCompiler` использует embedded-протокол и может повторно использовать один процесс для множества компиляций.

`EmbeddedCompiler` рекомендуется, когда экземпляр можно использовать повторно. `Compiler` остаётся поддерживаемым для простых скриптов и обратной совместимости.

## Рекомендуемое использование

### Повторное использование EmbeddedCompiler

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\EmbeddedCompiler;
use Bugo\Sass\Options;

$compiler = new EmbeddedCompiler(
    options: new Options(style: 'compressed')
);

try {
    foreach ($sources as $source) {
        $results[] = $compiler->compileString($source);
    }
} finally {
    $compiler->close();
}
```

Повторно используйте один экземпляр вместо создания отдельного компилятора для каждого элемента. Вызывайте `close()` в блоке `finally`, чтобы нативный процесс освобождался как после успешного выполнения, так и после ошибки. Деструктор является страховкой, а не основным механизмом управления жизненным циклом в долгоживущих PHP-процессах.

### Компиляция файла через EmbeddedCompiler

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\EmbeddedCompiler;

$compiler = new EmbeddedCompiler();

try {
    $css = $compiler->compileFile(__DIR__ . '/assets/app.scss');
    file_put_contents(__DIR__ . '/assets/app.css', $css);
} finally {
    $compiler->close();
}
```

### Простая однократная CLI-компиляция

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;

$compiler = new Compiler();
$css      = $compiler->compileString('$color: red; body { color: $color; }');

echo $css;
```

`Compiler` не сохраняет дочерний процесс открытым, поэтому явный вызов `close()` ему не нужен.

## Основные операции

### Компиляция и сохранение только при изменении исходника

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\EmbeddedCompiler;
use Bugo\Sass\Exception;

$compiler = new EmbeddedCompiler();

try {
    $compiled = $compiler->compileFileAndSave(
        __DIR__ . '/assets/style.scss',
        __DIR__ . '/assets/style.css',
    );

    echo $compiled ? "CSS перекомпилирован.\n" : "Изменений не обнаружено.\n";
} catch (Exception $e) {
    echo 'Ошибка компиляции: ' . $e->getMessage();
} finally {
    $compiler->close();
}
```

### Source maps и сжатый вывод

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\EmbeddedCompiler;
use Bugo\Sass\Options;

$compiler = new EmbeddedCompiler(
    options: new Options(
        style: 'compressed',
        includeSources: true,
        sourceMapPath: 'inline',
    )
);

try {
    $css = $compiler->compileString('$color: red; body { color: $color; }');
} finally {
    $compiler->close();
}
```

## Параметры

| Параметр | Тип | Описание | Возможные значения | Фактическое значение по умолчанию |
|---|---|---|---|---|
| syntax | string | Синтаксис исходника | `scss`, `indented` или `sass` | `scss` |
| style | string | Стиль вывода | `compressed` или `expanded` | `expanded` |
| includeSources | bool | Включать исходный код в source map | `true` или `false` | `false` |
| loadPaths | array<string> | Пути поиска Sass-импортов | `['./libs', './node_modules']` | `[]` |
| quietDeps | bool | Подавлять предупреждения зависимостей | `true` или `false` | `false` |
| silenceDeprecations | array<string> | Подавляемые устаревания Sass | `['import', 'color-functions']` | `[]` |
| verbose | bool | Включить подробные сообщения Sass | `true` или `false` | `false` |
| sourceMapPath | string | `inline`, URL, директория или путь к файлу карты |  | отключено |
| url | string | URL исходника для Sass и source maps | файловый или HTTP(S) URL | автоматически для `compileFile()` |
| sourceFile | string | Виртуальное имя исходника для bridge | например `style.scss` | внутреннее значение bridge |

Параметры можно задать для экземпляра компилятора и переопределить для одного вызова метода:

```php
use Bugo\Sass\EmbeddedCompiler;
use Bugo\Sass\Options;

$compiler = new EmbeddedCompiler(
    options: new Options(
        style: 'expanded',
        quietDeps: true,
        loadPaths: ['/project/styles'],
    )
);

try {
    $css = $compiler->compileString($scss, new Options(
        style: 'compressed',
        quietDeps: false,
        loadPaths: [],
    ));
} finally {
    $compiler->close();
}
```

Опции уровня метода объединяются с настройками экземпляра:

- `null` наследует значение экземпляра;
- `false` явно переопределяет логическое значение экземпляра;
- `[]` явно очищает значение-массив экземпляра;
- `url` имеет приоритет над совместимым параметром `sourceFile`;
- успешная компиляция исходника, содержащего только комментарии, возвращает пустую строку.

## Ошибки и жизненный цикл процесса

Ошибки компиляции представлены исключением `Bugo\Sass\Exception`. Для ошибок embedded-транспорта и протокола используются более специализированные типы исключений, которые для вызывающего кода остаются ошибками компиляции.

`EmbeddedCompiler` управляет запуском процесса, framing протокола, восстановлением после принадлежащих компилятору транспортных ошибок и корректным завершением процесса. В долгоживущем приложении:

1. создайте один компилятор на определённое время жизни worker или batch;
2. повторно используйте его для связанных компиляций;
3. вызывайте `close()` из `finally` при завершении;
4. не используйте PHP-деструктор как основной механизм освобождения ресурсов.

Подробности восстановления и завершения приведены в документе [Жизненный цикл процесса и надёжность](docs/process-lifecycle.ru.md).

## Benchmark

Встроенный benchmark сравнивает cold-запуск и warm-переиспользование процесса для `Compiler`, `EmbeddedCompiler` и `scssphp/scssphp` при компиляции строк и файлов.

```bash
composer benchmark
php benchmark.php --runs=20 --warmup=3 --batch=2
```

Перед измерением benchmark проверяет совпадение результатов CLI и embedded Dart Sass. Результаты зависят от оборудования и окружения, поэтому запускайте benchmark локально и не воспринимайте показатели одной машины как универсальную гарантию.

См. [методику benchmark](docs/benchmark-methodology.ru.md) и [генерируемый отчёт](benchmark.md).

## Документация

- [Контракт компиляторов](docs/compiler-contract.ru.md)
- [Жизненный цикл процесса и надёжность](docs/process-lifecycle.ru.md)
- [Методика benchmark](docs/benchmark-methodology.ru.md)
