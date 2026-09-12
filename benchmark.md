# Benchmark

## Test Environment

- **SCSS code**: Randomly generated, contains 400 classes with 4 nesting levels, variables, mixins and loops
- **OS**: Windows 11 25H2 (Build 10.0.26200.9445)
- **PHP version**: 8.5.10
- **Testing method**: 10 runs + 2 warmup runs, with execution time and memory measurement

## Results

| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |
|------------|-------------|---------------|-------------|
| sass-embedded-php | 0.2086 | 777.53 | 3.33 |
| scssphp/scssphp | 1.3512 | 621.83 | 76.70 |
