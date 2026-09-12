# Compiler contract alignment

`Compiler` remains the CLI implementation and `EmbeddedCompiler` remains the embedded-protocol implementation. This phase does not rename either class and does not introduce a version 2 API.

## Shared contract

Both implementations provide compatible behavior for:

- storing and retrieving `Options`;
- fluent `setOptions()` calls;
- blank string input;
- expanded, compressed, and indented compilation;
- string and file compilation;
- load paths;
- conditional file writes;
- missing-file exceptions;
- source-map output modes.

The shared expectations live in `tests/Feature/CompilerContractTest.php` and run against both implementations.

## Intentional implementation-specific behavior

The following capabilities remain specific to `EmbeddedCompiler`:

- reuse of a long-running Dart Sass process;
- transport retry and lifecycle management;
- structured `LogEvent` collection;
- real-time log handlers;
- the embedded protocol `verbose` option.

The CLI implementation continues to expose its protected process and argument-building extension points. This phase does not change that inheritance contract.

## Alignment decisions

### Method-level options

Options passed directly to `compileString()` or `compileFile()` override only their non-null fields. All remaining values are inherited from the instance options supplied through the constructor or `setOptions()`. Explicit `false` and empty arrays are overrides; only `null` means inherit.

### Comment-only input

Valid Sass input that emits no CSS returns an empty string. A successful Dart Sass process with empty stdout is not treated as an error.

### Exceptions

Both implementations use the package exception hierarchy and guarantee stable error categories. Transport-specific wording, stderr, and diagnostic details are not required to match byte for byte.

### `url` and `sourceFile`

`url` is the primary canonical identifier. `sourceFile` is used only when `url` is not set.

## Completion criteria

- the shared contract matrix passes for both implementations;
- intentional differences are documented;
- accidental semantic differences are removed;
- public class names and roles remain unchanged;
- tests pass on PHP 8.2–8.5;
- coverage remains at 100%;
- PHP CS Fixer, Rector, and sass-spec pass.
