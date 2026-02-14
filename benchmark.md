# Benchmark

## Test Environment

- **SCSS code**: Randomly generated, contains 500 classes with 6 nesting levels, variables, mixins and loops
- **OS**: Windows 11 25H2 (Build 10.0.26200.7705)
- **PHP version**: 8.2.30
- **Testing method**: Compilation via `compileString()` with execution time measurement

## Results

| Compiler                         | Time (sec) | CSS Size (KB) | Memory (MB) |
|----------------------------------|------------|---------------|-------------|
| bugo/sass-embedded-php           | 0.9576     | 779.79        | 0.76        |
| bugo/sass-embedded-php-generator | 0.8977     | 779.79        | 0.76        |

*Note: These results are approximate. Run `php benchmark.php` from the project root to see the actual results.*
