# Benchmark methodology

The benchmark compares the CLI `Compiler`, the long-running `EmbeddedCompiler`, and `scssphp/scssphp` without changing their public APIs.

## Scenarios

Each selected fixture is compiled through both `compileString()` and `compileFile()` in two modes:

- **cold** creates and closes a compiler for every operation;
- **warm** reuses one compiler for the complete scenario.

The default fixture profiles are small (25 classes, 2 nesting levels), medium (100 classes, 3 levels), and large (400 classes, 4 levels). Generated fixtures are cached as `generated-<profile>.scss`; use `--regenerate` to replace them.

## Validation

Before timing begins, CLI and embedded Dart Sass output must match exactly. Every compiler must also produce non-empty file output. During a scenario, the output hash must remain stable across all measured operations.

`scssphp` output is not required to match Dart Sass byte for byte because formatting and implementation details may differ.

## Measurements

The report records per-operation mean, median, p95, throughput, CSS size, and PHP peak-memory delta. Native Dart Sass child-process memory is not included in the PHP memory metric.

Warmup operations are excluded from timing. Each measured run executes a configurable batch, and reported durations are normalized to one compilation.

## Reproduction

```bash
composer benchmark
php benchmark.php --runs=10 --warmup=2 --batch=3
php benchmark.php --profile=large --input=string
```

The report includes PHP, Dart Sass, and scssphp versions, OS and architecture, fixture sizes and SHA-256 hashes, and run parameters.
