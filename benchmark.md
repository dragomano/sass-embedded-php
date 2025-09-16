## Results

| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |
|------------|-------------|---------------|-------------|
| bugo/sass-embedded-php | 1.4567 | 3,559.42 | 28.00 |
| bugo/sass-embedded-php (optimized) | 1.4455 | 3,559.42 | 2.00 |

## Process Caching Performance (5 iterations)
| Compiler | Avg Time (sec) | Avg Memory (MB) |
|------------|----------------|----------------|
| bugo/sass-embedded-php | 1.4911 | 2.40 |
| bugo/sass-embedded-php (optimized) | 1.4922 | 0.00 |

## Optimizations Implemented

- **Process Caching**: Node.js processes are cached and reused to avoid spawning overhead
- **Streaming for Large Data**: Input data is processed in chunks to prevent memory exhaustion
- **Reduced JSON Overhead**: Only necessary data is transmitted between PHP and Node.js
- **Generator Support**: Large compilation results can be processed chunk-by-chunk using generators
- **Memory Limits**: Automatic streaming activation for files larger than 1MB

*Note: These results are approximate. To get actual results, run `php benchmark.php` in the project root.*
