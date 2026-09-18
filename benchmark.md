# Compiler benchmark

## Environment

- **Generated at**: 2026-09-18T07:51:52+00:00
- **OS**: Windows 11 25H2 (Build 10.0.26200.9457)
- **PHP version**: 8.6.0beta2
- **Dart Sass version**: 1.104.1
- **scssphp version**: v2.1.0
- **Measured runs**: 20
- **Warmup runs**: 3
- **Operations per measured run**: 2
- **Memory metric**: PHP peak-memory delta; native child-process memory is not included

## Fixtures

| Profile | SCSS size (KB) | SHA-256 |
|---|---:|---|
| small | 34.06 | `8b7d1280791c70642891a591c8168b2f11dbee180585bacd3e08da22d9c10aba` |
| medium | 129.76 | `ec6811af8bd6ce616c1800399bd2e1930283362e43a65d6d7b14dddb20fd1134` |
| large | 579.59 | `8654e0756e7ce06b348c72ac3b2f348ceb1a7a10cbcde3fd33815f3662262711` |

## Results

| Profile | Input | Mode | Compiler | Mean (ms) | Median (ms) | p95 (ms) | Compilations/s | CSS (KB) | PHP memory (MB) |
|---|---|---|---|---:|---:|---:|---:|---:|---:|
| small | string | cold | CLI Compiler | 227.535 | 227.270 | 228.581 | 4.39 | 45.38 | 0.20 |
| small | string | cold | EmbeddedCompiler | 226.517 | 226.435 | 227.218 | 4.41 | 45.38 | 0.47 |
| small | string | cold | scssphp/scssphp | 56.864 | 56.809 | 57.611 | 17.59 | 36.13 | 5.88 |
| small | string | warm | CLI Compiler | 227.361 | 227.132 | 228.655 | 4.40 | 45.38 | 0.20 |
| small | string | warm | EmbeddedCompiler | 15.156 | 15.063 | 15.585 | 65.98 | 45.38 | 0.44 |
| small | string | warm | scssphp/scssphp | 56.534 | 56.190 | 58.137 | 17.69 | 36.13 | 4.75 |
| small | file | cold | CLI Compiler | 212.594 | 212.162 | 213.773 | 4.70 | 45.38 | 0.20 |
| small | file | cold | EmbeddedCompiler | 226.362 | 226.228 | 227.950 | 4.42 | 45.38 | 0.33 |
| small | file | cold | scssphp/scssphp | 57.484 | 57.078 | 57.952 | 17.40 | 36.13 | 7.03 |
| small | file | warm | CLI Compiler | 212.509 | 212.120 | 214.480 | 4.71 | 45.38 | 0.20 |
| small | file | warm | EmbeddedCompiler | 15.155 | 15.214 | 15.401 | 65.99 | 45.38 | 0.34 |
| small | file | warm | scssphp/scssphp | 56.981 | 56.822 | 57.914 | 17.55 | 36.13 | 6.45 |
| medium | string | cold | CLI Compiler | 227.211 | 226.892 | 228.403 | 4.40 | 155.90 | 0.62 |
| medium | string | cold | EmbeddedCompiler | 243.294 | 241.608 | 248.911 | 4.11 | 155.90 | 1.48 |
| medium | string | cold | scssphp/scssphp | 202.641 | 202.798 | 205.636 | 4.93 | 122.59 | 16.41 |
| medium | string | warm | CLI Compiler | 227.232 | 227.243 | 228.404 | 4.40 | 155.90 | 0.62 |
| medium | string | warm | EmbeddedCompiler | 30.248 | 30.230 | 30.588 | 33.06 | 155.90 | 1.47 |
| medium | string | warm | scssphp/scssphp | 204.903 | 203.346 | 207.563 | 4.88 | 122.59 | 16.53 |
| medium | file | cold | CLI Compiler | 212.010 | 212.293 | 213.022 | 4.72 | 155.90 | 0.62 |
| medium | file | cold | EmbeddedCompiler | 244.999 | 242.293 | 249.209 | 4.08 | 155.90 | 0.97 |
| medium | file | cold | scssphp/scssphp | 204.938 | 204.225 | 209.558 | 4.88 | 122.59 | 24.74 |
| medium | file | warm | CLI Compiler | 212.264 | 211.396 | 213.340 | 4.71 | 155.90 | 0.62 |
| medium | file | warm | EmbeddedCompiler | 30.200 | 30.255 | 30.660 | 33.11 | 155.90 | 1.09 |
| medium | file | warm | scssphp/scssphp | 204.780 | 204.915 | 206.879 | 4.88 | 122.59 | 23.86 |
| large | string | cold | CLI Compiler | 227.342 | 227.297 | 228.538 | 4.40 | 670.72 | 2.63 |
| large | string | cold | EmbeddedCompiler | 333.385 | 332.389 | 338.928 | 3.00 | 670.72 | 6.25 |
| large | string | cold | scssphp/scssphp | 945.927 | 945.836 | 956.462 | 1.06 | 527.62 | 66.01 |
| large | string | warm | CLI Compiler | 244.287 | 226.769 | 331.489 | 4.09 | 670.72 | 2.63 |
| large | string | warm | EmbeddedCompiler | 201.929 | 203.332 | 226.469 | 4.95 | 670.72 | 4.98 |
| large | string | warm | scssphp/scssphp | 1191.827 | 1187.577 | 1567.949 | 0.84 | 527.62 | 66.01 |
| large | file | cold | CLI Compiler | 263.844 | 211.392 | 317.177 | 3.79 | 670.72 | 2.63 |
| large | file | cold | EmbeddedCompiler | 390.218 | 384.239 | 453.376 | 2.56 | 670.72 | 3.99 |
| large | file | cold | scssphp/scssphp | 1309.490 | 1312.638 | 1447.589 | 0.76 | 527.62 | 78.12 |
| large | file | warm | CLI Compiler | 295.506 | 316.537 | 422.390 | 3.38 | 670.72 | 2.63 |
| large | file | warm | EmbeddedCompiler | 215.300 | 211.232 | 241.183 | 4.64 | 670.72 | 3.28 |
| large | file | warm | scssphp/scssphp | 1364.369 | 1378.598 | 1676.610 | 0.73 | 527.62 | 78.12 |

Cold mode creates and closes a compiler for every operation. Warm mode reuses one compiler instance for the complete scenario.
