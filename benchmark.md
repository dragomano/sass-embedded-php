# Benchmark

## Test Environment

- **SCSS code**: Randomly generated, contains 400 classes with 4 nesting levels, variables, mixins and loops
- **OS**: Windows 11 25H2 (Build 10.0.26200.8246)
- **PHP version**: 8.5.5
- **Testing method**: 10 runs + 2 warmup runs, with execution time and memory measurement

## Results

| Compiler          | Time (sec) | CSS Size (KB) | Memory (MB) |
|-------------------|------------|---------------|-------------|
| sass-embedded-php | 0.2068     | 745.20        | 3.27        |
| scssphp/scssphp   | 1.2830     | 622.32        | 76.71       |
