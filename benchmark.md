# Benchmark

## Test Environment

- **SCSS code**: Randomly generated, contains 1200 classes with 4 nesting levels, variables, mixins and loops (1757 KB source → 1551 KB CSS)
- **CSS Output**: 1551.91 KB (> 1 MB threshold, streaming activated in bridge.js)
- **Test Modes**:
  - `non-persistent`: Standard `compileString()` without persistent mode (uses static process cache)
  - `persistent`: `compileInPersistentMode()` with long-lived Node.js process
  - `persistent-generator`: `compileStringAsGenerator()` with persistent mode + streaming
- **OS**: Windows 11 25H2 (Build 10.0.26200.8037)
- **PHP version**: 8.5.4
- **Testing method**: 30 runs + 2 warmup runs, with execution time and memory measurement

## Results

| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |
|------------|-------------|---------------|-------------|
| bugo/sass-embedded-php-non-persistent | 1.2390 | 1,551.91 | 1.52 |
| bugo/sass-embedded-php-persistent | 0.9966 | 1,551.91 | 1.52 |
| bugo/sass-embedded-php-persistent-generator | 0.8865 | 1,551.84 | 1.52 |
