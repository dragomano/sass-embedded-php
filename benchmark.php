<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Bugo\BenchmarkUtils\BenchmarkRunner;
use Bugo\BenchmarkUtils\ScssGenerator;
use Bugo\Sass\Compiler as EmbeddedCompiler;
use Bugo\Sass\Options;

$args = $_SERVER['argv'] ?? [];
$forceRegenerate = in_array('--regenerate', $args, true);
$scssFile = __DIR__ . DIRECTORY_SEPARATOR . 'generated.scss';

if (! $forceRegenerate && file_exists($scssFile)) {
    $scss = (string) file_get_contents($scssFile);
    echo "Using existing generated.scss\n";
} else {
    // Generate large SCSS (>1MB output CSS to activate streaming in bridge.js)
    // 1000+ classes with 4 nesting levels generates ~2MB CSS (triggers streaming)
    $scss = ScssGenerator::generate(1200, 4);
    file_put_contents($scssFile, $scss, LOCK_EX);
    echo "Generated SCSS saved to generated.scss (extra large for streaming test)\n";
}

echo 'SCSS size: ' . strlen($scss) . " bytes\n";

$results = (new BenchmarkRunner())
    ->setScssCode($scss)
    ->setRuns(30)
    ->setWarmupRuns(2)
    ->setOutputDir(__DIR__)
    // Non-persistent variant (baseline - uses static process cache)
    ->addCompiler('bugo/sass-embedded-php-non-persistent', function () {
        $compiler = new EmbeddedCompiler(nodePath: 'node');
        $compiler->setOptions(new Options(
            style: 'compressed',
            sourceMapPath: 'result-sass-embedded-php-non-persistent.css.map',
            sourceFile: 'generated.scss',
        ));
        // Do NOT enable persistent mode - use standard process caching

        return new class ($compiler) {
            public function __construct(private readonly EmbeddedCompiler $compiler) {}

            public function compileInPersistentMode(string $source): string
            {
                return $this->compiler->compileString($source);
            }
        };
    })
    // Persistent standard variant (for comparison)
    ->addCompiler('bugo/sass-embedded-php-persistent', function () {
        $compiler = new EmbeddedCompiler(nodePath: 'node');
        $compiler->setOptions(new Options(
            style: 'compressed',
            sourceMapPath: 'result-sass-embedded-php-persistent.css.map',
            sourceFile: 'generated.scss',
        ));

        return $compiler->enablePersistentMode();
    })
    // Persistent generator variant (with streamResult for large output)
    ->addCompiler('bugo/sass-embedded-php-persistent-generator', function () {
        $compiler = new EmbeddedCompiler(nodePath: 'node');
        $compiler->setOptions(new Options(
            style: 'compressed',
            sourceMapPath: 'result-sass-embedded-php-persistent-generator.css.map',
            sourceFile: 'generated.scss',
            streamResult: true,
        ));
        $compiler->enablePersistentMode();

        return new class ($compiler) {
            public function __construct(private readonly EmbeddedCompiler $compiler) {}

            public function compileInPersistentMode(string $source): string
            {
                return implode('', iterator_to_array($this->compiler->compileStringAsGenerator($source)));
            }
        };
    })
    ->run();

echo PHP_EOL . '## Results' . PHP_EOL;
echo BenchmarkRunner::formatTable($results);

BenchmarkRunner::updateMarkdownFile('benchmark.md', $results);
