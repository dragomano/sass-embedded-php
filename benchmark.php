<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Bugo\BenchmarkUtils\BenchmarkRunner;
use Bugo\BenchmarkUtils\ReportGenerator;
use Bugo\BenchmarkUtils\ScssGenerator;
use Bugo\Sass\Compiler as SassCompiler;
use ScssPhp\ScssPhp\Compiler as ScssCompiler;

$args = $_SERVER['argv'] ?? [];
$forceRegenerate = in_array('--regenerate', $args, true);
$scssFile = __DIR__ . DIRECTORY_SEPARATOR . 'generated.scss';

if (! $forceRegenerate && file_exists($scssFile)) {
    $scss = (string) file_get_contents($scssFile);
    echo "Using existing generated.scss\n";
} else {
    $scss = ScssGenerator::generate(400, 4);
    file_put_contents($scssFile, $scss, LOCK_EX);
    echo "Generated SCSS saved to generated.scss\n";
}

echo 'SCSS size: ' . strlen($scss) . " bytes\n";

$results = (new BenchmarkRunner())
    ->setSourceFile($scssFile)
    ->setRuns(10)
    ->setWarmupRuns(2)
    ->setOutputDir(__DIR__)
    ->addCompiler('sass-embedded-php', fn() => new SassCompiler())
    ->addCompiler('scssphp/scssphp', fn() => new ScssCompiler())
    ->run();

echo PHP_EOL . '## Results' . PHP_EOL;
echo ReportGenerator::formatTable($results);

ReportGenerator::updateMarkdownFile('benchmark.md', $results);
