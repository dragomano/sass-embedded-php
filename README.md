# sass-embedded-php

![PHP](https://img.shields.io/badge/PHP-^8.2-blue.svg?style=flat)
[![Coverage Status](https://coveralls.io/repos/github/dragomano/sass-embedded-php/badge.svg?branch=main)](https://coveralls.io/github/dragomano/sass-embedded-php?branch=main)

[По-русски](README.ru.md)

Compile SCSS/SASS to CSS from PHP using native Dart Sass. The package provides a reusable embedded-protocol compiler and a simple CLI compiler with the same compilation contract.

---

## Installation

```bash
composer require bugo/sass-embedded-php
```

The matching native Dart Sass binary is installed into the package automatically.

## Choosing a compiler

| Scenario | Recommended implementation |
|---|---|
| Repeated compilation, queue worker, daemon, or batch | `EmbeddedCompiler` |
| One-off script with the simplest lifecycle | `Compiler` |
| Existing integration that already uses the CLI implementation | `Compiler` |
| Highest throughput from a reused process | `EmbeddedCompiler` |

Both classes implement `CompilerInterface` and expose the same option and compilation methods. `Compiler` starts a Dart Sass CLI process for every compilation. `EmbeddedCompiler` speaks the embedded protocol and can reuse one process across many compilations.

`EmbeddedCompiler` is the recommended implementation when the instance can be reused. `Compiler` remains supported for simple scripts and backwards compatibility.

## Recommended usage

### Reusing EmbeddedCompiler

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

Reuse the instance instead of constructing one compiler per item. Call `close()` in a `finally` block so the native process is released after success or failure. The destructor is a fallback, not the preferred lifecycle mechanism for long-running PHP processes.

### Compiling a file with EmbeddedCompiler

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

### Simple one-off CLI compilation

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Bugo\Sass\Compiler;

$compiler = new Compiler();
$css      = $compiler->compileString('$color: red; body { color: $color; }');

echo $css;
```

`Compiler` does not keep a child process open and therefore does not require an explicit `close()` call.

## Common operations

### Compiling and saving only when the source changed

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

    echo $compiled ? "CSS recompiled.\n" : "No changes detected.\n";
} catch (Exception $e) {
    echo 'Compilation error: ' . $e->getMessage();
} finally {
    $compiler->close();
}
```

### Source maps and compressed output

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

## Options

| Option | Type | Description | Possible values | Effective default |
|---|---|---|---|---|
| syntax | string | Input syntax | `scss`, `indented`, or `sass` | `scss` |
| style | string | Output style | `compressed` or `expanded` | `expanded` |
| includeSources | bool | Include source code in a source map | `true` or `false` | `false` |
| loadPaths | array<string> | Paths used to resolve Sass imports | `['./libs', './node_modules']` | `[]` |
| quietDeps | bool | Suppress warnings from dependencies | `true` or `false` | `false` |
| silenceDeprecations | array<string> | Sass deprecations to suppress | `['import', 'color-functions']` | `[]` |
| verbose | bool | Enable verbose Sass messages | `true` or `false` | `false` |
| sourceMapPath | string | `inline`, URL, directory, or source-map file path |  | disabled |
| url | string | Source URL used by Sass and source maps | file or HTTP(S) URL | automatic for `compileFile()` |
| sourceFile | string | Virtual source filename for bridge processing | for example `style.scss` | internal bridge default |

Options can be configured for the compiler instance and overridden for one method call:

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

Method-level options are merged with instance defaults:

- `null` inherits the instance value;
- `false` explicitly overrides a boolean instance value;
- `[]` explicitly clears an array instance value;
- `url` takes precedence over the compatibility `sourceFile` value;
- successfully compiling comment-only input returns an empty string.

## Errors and process lifecycle

Compilation failures throw `Bugo\Sass\Exception`. Embedded transport and protocol failures use more specific exception types while remaining compilation failures from the caller's perspective.

`EmbeddedCompiler` manages process startup, protocol framing, recovery from compiler-owned transport failures, and graceful shutdown. In long-running applications:

1. create one compiler for a defined worker or batch lifetime;
2. reuse it for related compilations;
3. call `close()` from `finally` during shutdown;
4. do not rely on the PHP destructor as the primary cleanup strategy.

See [Process lifecycle and reliability](docs/process-lifecycle.md) for recovery and shutdown details.

## Benchmark

The included benchmark compares cold startup and warm process reuse for `Compiler`, `EmbeddedCompiler`, and `scssphp/scssphp` across string and file inputs.

```bash
composer benchmark
php benchmark.php --runs=20 --warmup=3 --batch=2
```

The benchmark validates matching CLI and embedded Dart Sass output before timing. Results depend on hardware and environment; run it locally rather than treating one machine's numbers as a universal guarantee.

See [Benchmark methodology](docs/benchmark-methodology.md) and [the generated report](benchmark.md).

## Documentation

- [Compiler contract](docs/compiler-contract.md)
- [Process lifecycle and reliability](docs/process-lifecycle.md)
- [Benchmark methodology](docs/benchmark-methodology.md)
