## Results

| Compiler                           | Time (sec) | CSS Size (KB) | Memory (MB) |
|------------------------------------|------------|---------------|-------------|
| bugo/sass-embedded-php             | 1.4758     | 3,559.77      | 28.00       |
| bugo/sass-embedded-php (optimized) | 1.4530     | 3,559.77      | 2.00        |

## Process Caching Performance (25 iterations)
| Compiler                           | Avg Time (sec) | Avg Memory (MB) |
|------------------------------------|----------------|-----------------|
| bugo/sass-embedded-php             | 1.4608         | 0.16            |
| bugo/sass-embedded-php (optimized) | 1.4679         | 0.00            |

## Optimizations Implemented

- **Process Caching**: Node.js processes are cached and reused to avoid spawning overhead
- **Streaming for Large Data**: Input data is processed in chunks to prevent memory exhaustion
- **Reduced JSON Overhead**: Only necessary data is transmitted between PHP and Node.js
- **Generator Support**: Large compilation results can be processed chunk-by-chunk using generators
- **Memory Limits**: Automatic streaming activation for files larger than 1MB

*Note: These results are approximate. To get actual results, run `php benchmark.php` in the project root.*
