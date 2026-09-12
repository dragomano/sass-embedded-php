<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Bugo\BenchmarkUtils\OsDetector;
use Bugo\BenchmarkUtils\ScssGenerator;
use Bugo\Sass\Compiler;
use Bugo\Sass\CompilerInterface;
use Bugo\Sass\EmbeddedCompiler;
use Bugo\Sass\Options;
use Composer\InstalledVersions;
use ScssPhp\ScssPhp\Compiler as ScssPhpCompiler;
use ScssPhp\ScssPhp\OutputStyle;

$arguments = getopt('', [
    'runs::',
    'warmup::',
    'batch::',
    'profile::',
    'input::',
    'regenerate',
    'no-write',
]);

$runs        = max(1, (int) ($arguments['runs'] ?? 5));
$warmupRuns  = max(0, (int) ($arguments['warmup'] ?? 1));
$batchSize   = max(1, (int) ($arguments['batch'] ?? 2));
$regenerate  = array_key_exists('regenerate', $arguments);
$writeReport = ! array_key_exists('no-write', $arguments);

$profiles = [
    'small'  => ['classes' => 25, 'levels' => 2],
    'medium' => ['classes' => 100, 'levels' => 3],
    'large'  => ['classes' => 400, 'levels' => 4],
];

$selectedProfiles = benchmarkSelections($arguments['profile'] ?? null, array_keys($profiles));
$selectedInputs   = benchmarkSelections($arguments['input'] ?? null, ['string', 'file']);

$factories = [
    'CLI Compiler' => static fn(): Compiler => new Compiler(new Options(style: 'compressed')),
    'EmbeddedCompiler' => static fn(): EmbeddedCompiler => new EmbeddedCompiler(
        options: new Options(style: 'compressed')
    ),
    'scssphp/scssphp' => static function (): ScssPhpCompiler {
        $compiler = new ScssPhpCompiler();
        $compiler->setOutputStyle(OutputStyle::COMPRESSED);

        return $compiler;
    },
];

$fixtures = [];
$results  = [];

foreach ($selectedProfiles as $profileName) {
    if (! isset($profiles[$profileName])) {
        throw new InvalidArgumentException("Unknown benchmark profile: $profileName");
    }

    $profile = $profiles[$profileName];
    $file    = __DIR__ . "/generated-$profileName.scss";

    if (! $regenerate && is_file($file)) {
        $scss = (string) file_get_contents($file);
    } else {
        $scss = ScssGenerator::generate($profile['classes'], $profile['levels']);
        file_put_contents($file, $scss, LOCK_EX);
    }

    $fixtures[$profileName] = [
        'bytes'  => strlen($scss),
        'sha256' => hash('sha256', $scss),
    ];

    validateDartImplementations($factories, $scss, $file);

    foreach ($selectedInputs as $inputKind) {
        if (! in_array($inputKind, ['string', 'file'], true)) {
            throw new InvalidArgumentException("Unknown benchmark input: $inputKind");
        }

        foreach (['cold', 'warm'] as $mode) {
            foreach ($factories as $compilerName => $factory) {
                echo sprintf(
                    "Benchmarking %s: %s, %s, %s...\n",
                    $compilerName,
                    $profileName,
                    $inputKind,
                    $mode
                );

                $results[] = benchmarkScenario(
                    compilerName: $compilerName,
                    factory: $factory,
                    scss: $scss,
                    file: $file,
                    profile: $profileName,
                    inputKind: $inputKind,
                    mode: $mode,
                    runs: $runs,
                    warmupRuns: $warmupRuns,
                    batchSize: $batchSize
                );
            }
        }
    }
}

$report = benchmarkReport(
    results: $results,
    fixtures: $fixtures,
    runs: $runs,
    warmupRuns: $warmupRuns,
    batchSize: $batchSize
);

echo PHP_EOL . $report;

if ($writeReport) {
    file_put_contents(__DIR__ . '/benchmark.md', $report, LOCK_EX);
}

/** @return list<string> */
function benchmarkSelections(string|false|null $value, array $defaults): array
{
    if ($value === null || $value === false || trim($value) === '') {
        return $defaults;
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
}

function validateDartImplementations(array $factories, string $scss, string $file): void
{
    $outputs = [];

    foreach (['CLI Compiler', 'EmbeddedCompiler'] as $name) {
        $compiler = $factories[$name]();

        try {
            $outputs[$name] = compileBenchmarkInput($compiler, $scss, $file, 'string');
        } finally {
            closeBenchmarkCompiler($compiler);
        }
    }

    if ($outputs['CLI Compiler'] !== $outputs['EmbeddedCompiler']) {
        throw new RuntimeException('CLI and embedded compilers produced different validation output.');
    }

    foreach ($factories as $name => $factory) {
        $compiler = $factory();

        try {
            $output = compileBenchmarkInput($compiler, $scss, $file, 'file');

            if ($output === '') {
                throw new RuntimeException("$name produced empty validation output.");
            }
        } finally {
            closeBenchmarkCompiler($compiler);
        }
    }
}

function benchmarkScenario(
    string $compilerName,
    callable $factory,
    string $scss,
    string $file,
    string $profile,
    string $inputKind,
    string $mode,
    int $runs,
    int $warmupRuns,
    int $batchSize,
): array {
    $times      = [];
    $peakMemory = 0.0;
    $outputHash = null;
    $cssBytes   = 0;
    $compiler   = $mode === 'warm' ? $factory() : null;

    try {
        for ($index = 0; $index < $warmupRuns; $index++) {
            $warmupCompiler = $compiler ?? $factory();

            try {
                compileBenchmarkInput($warmupCompiler, $scss, $file, $inputKind);
            } finally {
                if ($compiler === null) {
                    closeBenchmarkCompiler($warmupCompiler);
                }
            }
        }

        for ($run = 0; $run < $runs; $run++) {
            gc_collect_cycles();
            memory_reset_peak_usage();

            $memoryBaseline = memory_get_usage();
            $startedAt      = hrtime(true);

            for ($operation = 0; $operation < $batchSize; $operation++) {
                $currentCompiler = $compiler ?? $factory();

                try {
                    $css = compileBenchmarkInput($currentCompiler, $scss, $file, $inputKind);
                } finally {
                    if ($compiler === null) {
                        closeBenchmarkCompiler($currentCompiler);
                    }
                }

                $currentHash = hash('sha256', $css);
                $outputHash ??= $currentHash;

                if ($outputHash !== $currentHash) {
                    throw new RuntimeException("$compilerName produced unstable benchmark output.");
                }

                $cssBytes = strlen($css);
            }

            $elapsed     = (hrtime(true) - $startedAt) / 1e9 / $batchSize;
            $memoryDelta = (memory_get_peak_usage() - $memoryBaseline) / 1024 / 1024;
            $times[]     = $elapsed;
            $peakMemory  = max($peakMemory, $memoryDelta);
        }
    } finally {
        if ($compiler !== null) {
            closeBenchmarkCompiler($compiler);
        }
    }

    sort($times);

    $mean   = array_sum($times) / count($times);
    $median = benchmarkPercentile($times, 0.5);
    $p95    = benchmarkPercentile($times, 0.95);

    return [
        'profile'    => $profile,
        'input'      => $inputKind,
        'mode'       => $mode,
        'compiler'   => $compilerName,
        'mean'       => $mean,
        'median'     => $median,
        'p95'        => $p95,
        'throughput' => 1 / $mean,
        'memory'     => $peakMemory,
        'cssBytes'   => $cssBytes,
        'hash'       => $outputHash,
    ];
}

function compileBenchmarkInput(object $compiler, string $scss, string $file, string $inputKind): string
{
    if ($inputKind === 'file') {
        $result = $compiler->compileFile($file);
    } else {
        $result = $compiler->compileString($scss);
    }

    if (is_object($result) && method_exists($result, 'getCss')) {
        return (string) $result->getCss();
    }

    return (string) $result;
}

function closeBenchmarkCompiler(object $compiler): void
{
    if ($compiler instanceof EmbeddedCompiler) {
        $compiler->close();
    }
}

function benchmarkPercentile(array $sortedValues, float $percentile): float
{
    $index = (int) ceil(count($sortedValues) * $percentile) - 1;

    return $sortedValues[max(0, min($index, count($sortedValues) - 1))];
}

function benchmarkReport(array $results, array $fixtures, int $runs, int $warmupRuns, int $batchSize): string
{
    $sassVersion = is_file(__DIR__ . '/bin/.sass-version')
        ? trim((string) file_get_contents(__DIR__ . '/bin/.sass-version'))
        : 'unknown';
    $scssPhpVersion = InstalledVersions::getPrettyVersion('scssphp/scssphp') ?? 'unknown';

    $lines = [
        '# Compiler benchmark',
        '',
        '## Environment',
        '',
        '- **Generated at**: ' . date(DATE_ATOM),
        '- **OS**: ' . OsDetector::detect(),
        '- **PHP version**: ' . PHP_VERSION,
        '- **Dart Sass version**: ' . $sassVersion,
        '- **scssphp version**: ' . $scssPhpVersion,
        '- **Measured runs**: ' . $runs,
        '- **Warmup runs**: ' . $warmupRuns,
        '- **Operations per measured run**: ' . $batchSize,
        '- **Memory metric**: PHP peak-memory delta; native child-process memory is not included',
        '',
        '## Fixtures',
        '',
        '| Profile | SCSS size (KB) | SHA-256 |',
        '|---|---:|---|',
    ];

    foreach ($fixtures as $name => $fixture) {
        $lines[] = sprintf('| %s | %.2f | `%s` |', $name, $fixture['bytes'] / 1024, $fixture['sha256']);
    }

    $lines[] = '';
    $lines[] = '## Results';
    $lines[] = '';
    $lines[] = '| Profile | Input | Mode | Compiler | Mean (ms) | Median (ms) | p95 (ms) | Compilations/s | CSS (KB) | PHP memory (MB) |';
    $lines[] = '|---|---|---|---|---:|---:|---:|---:|---:|---:|';

    foreach ($results as $result) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | %.3f | %.3f | %.3f | %.2f | %.2f | %.2f |',
            $result['profile'],
            $result['input'],
            $result['mode'],
            $result['compiler'],
            $result['mean'] * 1000,
            $result['median'] * 1000,
            $result['p95'] * 1000,
            $result['throughput'],
            $result['cssBytes'] / 1024,
            $result['memory']
        );
    }

    $lines[] = '';
    $lines[] = 'Cold mode creates and closes a compiler for every operation. Warm mode reuses one compiler instance for the complete scenario.';
    $lines[] = '';

    return implode(PHP_EOL, $lines);
}
