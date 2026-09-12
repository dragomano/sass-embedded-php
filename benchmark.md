# Compiler benchmark

## Environment

- **Generated at**: 2026-09-12T03:58:58+00:00
- **OS**: Windows 11 25H2 (Build 10.0.26200.9445)
- **PHP version**: 8.6.0beta2
- **Dart Sass version**: 1.104.0
- **scssphp version**: v2.1.0
- **Measured runs**: 20
- **Warmup runs**: 3
- **Operations per measured run**: 2
- **Memory metric**: PHP peak-memory delta; native child-process memory is not included

## Fixtures

| Profile | SCSS size (KB) | SHA-256 |
|---|---:|---|
| small | 34.00 | `59569ce8e6b36678156f9432fa5c2d5ce48676d986e285064471b79048518e3b` |
| medium | 129.65 | `9f037493a69b3534105d1e25960bfe4b0cd014ff7cf0cef5b163eadc3e5a144b` |
| large | 579.62 | `80bc5be4409f23bcd5694a2f8ea0763eaec21dd4e9afc2df1051e569cd51db82` |

## Results

| Profile | Input | Mode | Compiler | Mean (ms) | Median (ms) | p95 (ms) | Compilations/s | CSS (KB) | PHP memory (MB) |
|---|---|---|---|---:|---:|---:|---:|---:|---:|
| small | string | cold | CLI Compiler | 232.749 | 232.884 | 233.806 | 4.30 | 45.29 | 0.20 |
| small | string | cold | EmbeddedCompiler | 60.803 | 60.713 | 61.534 | 16.45 | 45.29 | 0.36 |
| small | string | cold | scssphp/scssphp | 63.440 | 61.777 | 75.904 | 15.76 | 36.01 | 5.87 |
| small | string | warm | CLI Compiler | 232.728 | 233.080 | 234.151 | 4.30 | 45.29 | 0.20 |
| small | string | warm | EmbeddedCompiler | 17.450 | 15.267 | 22.928 | 57.31 | 45.29 | 0.34 |
| small | string | warm | scssphp/scssphp | 61.269 | 61.103 | 62.493 | 16.32 | 36.01 | 4.75 |
| small | file | cold | CLI Compiler | 209.862 | 210.148 | 216.387 | 4.77 | 45.29 | 0.20 |
| small | file | cold | EmbeddedCompiler | 60.989 | 61.096 | 61.431 | 16.40 | 45.29 | 0.26 |
| small | file | cold | scssphp/scssphp | 62.590 | 62.317 | 66.752 | 15.98 | 36.01 | 7.04 |
| small | file | warm | CLI Compiler | 211.131 | 209.510 | 216.889 | 4.74 | 45.29 | 0.20 |
| small | file | warm | EmbeddedCompiler | 20.124 | 22.493 | 23.268 | 49.69 | 45.29 | 0.24 |
| small | file | warm | scssphp/scssphp | 62.957 | 61.908 | 64.136 | 15.88 | 36.01 | 6.46 |
| medium | string | cold | CLI Compiler | 231.619 | 231.586 | 236.228 | 4.32 | 155.93 | 0.62 |
| medium | string | cold | EmbeddedCompiler | 76.510 | 76.087 | 76.960 | 13.07 | 155.93 | 1.17 |
| medium | string | cold | scssphp/scssphp | 221.192 | 220.331 | 224.678 | 4.52 | 122.51 | 16.41 |
| medium | string | warm | CLI Compiler | 232.509 | 232.492 | 233.877 | 4.30 | 155.93 | 0.62 |
| medium | string | warm | EmbeddedCompiler | 35.797 | 30.965 | 45.711 | 27.94 | 155.93 | 1.15 |
| medium | string | warm | scssphp/scssphp | 224.467 | 221.698 | 235.219 | 4.45 | 122.51 | 16.53 |
| medium | file | cold | CLI Compiler | 209.222 | 209.737 | 217.218 | 4.78 | 155.93 | 0.62 |
| medium | file | cold | EmbeddedCompiler | 76.069 | 75.868 | 76.831 | 13.15 | 155.93 | 0.79 |
| medium | file | cold | scssphp/scssphp | 223.002 | 221.638 | 236.490 | 4.48 | 122.51 | 24.74 |
| medium | file | warm | CLI Compiler | 211.581 | 210.006 | 217.479 | 4.73 | 155.93 | 0.62 |
| medium | file | warm | EmbeddedCompiler | 34.842 | 30.589 | 45.171 | 28.70 | 155.93 | 0.77 |
| medium | file | warm | scssphp/scssphp | 224.348 | 222.360 | 232.656 | 4.46 | 122.51 | 23.86 |
| large | string | cold | CLI Compiler | 229.998 | 232.862 | 236.087 | 4.35 | 671.00 | 2.63 |
| large | string | cold | EmbeddedCompiler | 157.606 | 153.264 | 168.655 | 6.34 | 671.00 | 5.00 |
| large | string | cold | scssphp/scssphp | 1009.033 | 1007.440 | 1035.572 | 0.99 | 527.90 | 66.02 |
| large | string | warm | CLI Compiler | 244.633 | 234.953 | 331.124 | 4.09 | 671.00 | 2.63 |
| large | string | warm | EmbeddedCompiler | 136.532 | 136.616 | 145.305 | 7.32 | 671.00 | 4.98 |
| large | string | warm | scssphp/scssphp | 1022.960 | 1018.257 | 1055.138 | 0.98 | 527.90 | 66.02 |
| large | file | cold | CLI Compiler | 206.853 | 204.751 | 212.449 | 4.83 | 671.00 | 2.63 |
| large | file | cold | EmbeddedCompiler | 163.128 | 165.544 | 167.282 | 6.13 | 671.00 | 3.30 |
| large | file | cold | scssphp/scssphp | 1005.022 | 997.427 | 1053.362 | 1.00 | 527.90 | 78.12 |
| large | file | warm | CLI Compiler | 206.687 | 204.832 | 212.107 | 4.84 | 671.00 | 2.63 |
| large | file | warm | EmbeddedCompiler | 134.376 | 129.720 | 152.027 | 7.44 | 671.00 | 3.28 |
| large | file | warm | scssphp/scssphp | 1006.166 | 1002.667 | 1024.996 | 0.99 | 527.90 | 78.12 |

Cold mode creates and closes a compiler for every operation. Warm mode reuses one compiler instance for the complete scenario.
