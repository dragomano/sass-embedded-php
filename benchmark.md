# Benchmark

## Test Environment

- **SCSS code**: Randomly generated, contains 500 classes with 6 nesting levels, variables, mixins and loops
- **OS**: Windows 11 25H2 (Build 10.0.26200.7840)
- **PHP version**: 8.5.1
- **Testing method**: Compilation via `compileString()` with execution time measurement

## Results

| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |
|------------|-------------|---------------|-------------|
| bugo/sass-embedded-php | 0.6054 | 845.66 | 0.84 |
| bugo/sass-embedded-php-generator | 0.5996 | 845.67 | 0.84 |

*Note: These results are approximate. Run `php benchmark.php` from the project root to see the actual results.*
