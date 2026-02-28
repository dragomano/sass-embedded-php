<?php

require_once 'vendor/autoload.php';

use Bugo\BenchmarkUtils\ScssGenerator;
use Bugo\BenchmarkUtils\BenchmarkRunner;
use Bugo\Sass\Compiler as EmbeddedCompiler;

$scss = ScssGenerator::generate(500, 6);

file_put_contents('generated.scss', $scss, LOCK_EX);

echo "Generated SCSS saved to generated.scss\n";
echo "SCSS size: " . strlen($scss) . " bytes\n";

$results = (new BenchmarkRunner())
    ->setScssCode($scss)
    ->setRuns(30)
    ->setWarmupRuns(2)
    ->setOutputDir(__DIR__)
    ->addCompiler('bugo/sass-embedded-php', function() {
        $compiler = new EmbeddedCompiler(nodePath: 'node');
        $compiler->setOptions([
            'style'         => 'compressed',
            'sourceMap'     => true,
            'sourceFile'    => 'generated.scss',
            'sourceMapPath' => 'result-sass-embedded-php.css.map',
            'streamResult'  => false,
        ]);

        return $compiler->enablePersistentMode();
    })
    ->addCompiler('bugo/sass-embedded-php-generator', function() {
        $compiler = new EmbeddedCompiler(nodePath: 'node');
        $compiler->setOptions([
            'style'         => 'compressed',
            'sourceMap'     => true,
            'sourceFile'    => 'generated.scss',
            'sourceMapPath' => 'result-sass-embedded-php-generator.css.map',
            'streamResult'  => true,
        ]);

        return $compiler->enablePersistentMode();
    })
    ->run();

echo PHP_EOL . '## Results' . PHP_EOL;
echo BenchmarkRunner::formatTable($results);

BenchmarkRunner::updateMarkdownFile('benchmark.md', $results);
