<?php

require_once 'vendor/autoload.php';

use Bugo\BenchmarkUtils\OsDetector;
use Bugo\BenchmarkUtils\ScssGenerator;
use Bugo\Sass\Compiler as EmbeddedCompiler;

$scss = ScssGenerator::generate(500, 6);

file_put_contents('generated.scss', $scss, LOCK_EX);

echo "Generated SCSS saved to generated.scss\n";
echo "SCSS size: " . strlen($scss) . " bytes\n";

$compilers = [
    'bugo/sass-embedded-php' => function() {
        $compiler = new EmbeddedCompiler(nodePath: 'node');
        $compiler->setOptions([
            'style'         => 'compressed',
            'sourceMap'     => true,
            'sourceFile'    => 'generated.scss',
            'sourceMapPath' => 'result-sass-embedded-php.css.map',
            'streamResult'  => false,
        ]);

        return $compiler;
    },
    'bugo/sass-embedded-php-generator' => function() {
        $compiler = new EmbeddedCompiler(nodePath: 'node');
        $compiler->setOptions([
            'style'         => 'compressed',
            'sourceMap'     => true,
            'sourceFile'    => 'generated.scss',
            'sourceMapPath' => 'result-sass-embedded-php-generator.css.map',
            'streamResult'  => true,
        ]);

        return $compiler;
    },
];

$results = [];
$runs = 30;

foreach ($compilers as $name => $compilerFactory) {
    for ($warmup = 0; $warmup < 2; $warmup++) {
        $compiler = $compilerFactory();
        $compiler->compileString($scss);
    }

    $times       = [];
    $css         = '';
    $map         = null;
    $maxMemDelta = 0;
    $package     = str_replace('/', '-', $name);
    $cssMap      = "result-$package.css.map";

    try {
        for ($i = 0; $i < $runs; $i++) {
            $memBefore = memory_get_usage();
            $start     = hrtime(true);
            $compiler  = $compilerFactory();

            $css = $compiler->compileString($scss);

            if ($i === 0 && file_exists($cssMap)) {
                $map = file_get_contents($cssMap);
            }

            $times[]     = (hrtime(true) - $start) / 1e9;
            $memAfter    = memory_get_usage();
            $maxMemDelta = max($maxMemDelta, $memAfter - $memBefore);

            unset($compiler, $result);
        }

        sort($times);
        $trim = min(2, intdiv(count($times) - 1, 2));
        for ($j = 0; $j < $trim; $j++) {
            array_shift($times);
            array_pop($times);
        }

        $time    = array_sum($times) / count($times);
        $memUsed = $maxMemDelta / 1024 / 1024;

        $cssFileName = "result-$package.css";
        if ($name === 'bugo/sass-embedded-php-generator') {
            $cssFileName = "result-$package-generator.css";
        }

        file_put_contents($cssFileName, $css, LOCK_EX);
        $cssSize = filesize($cssFileName) / 1024;

        $results[$name] = ['time' => $time, 'size' => $cssSize, 'memory' => $memUsed];
    } catch (Exception $e) {
        echo "General error in $name: " . $e->getMessage() . PHP_EOL;
        $results[$name] = ['time' => 'Error', 'size' => 'N/A', 'memory' => 'N/A'];
    }
}

// Output results in console
echo PHP_EOL . '## Results' . PHP_EOL;
echo '| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |' . PHP_EOL;
echo '|------------|-------------|---------------|-------------|' . PHP_EOL;

$tableData = '';
foreach ($results as $name => $data) {
    $timeStr   = is_numeric($data['time']) ? number_format($data['time'], 4) : $data['time'];
    $sizeStr   = is_numeric($data['size']) ? number_format($data['size'], 2) : $data['size'];
    $memStr    = is_numeric($data['memory']) ? number_format($data['memory'], 2) : $data['memory'];
    $freshData = "| $name | $timeStr | $sizeStr | $memStr |" . PHP_EOL;
    $tableData .= $freshData;
    echo $freshData;
}

// Now update the table in Markdown file
$mdContent = file_get_contents('benchmark.md');
$mdContent = preg_replace('/- \*\*OS\*\*: .+/', '- **OS**: ' . OsDetector::detect(), $mdContent);
$mdContent = preg_replace('/- \*\*PHP version\*\*: .+/', '- **PHP version**: ' . PHP_VERSION, $mdContent);

$tableStart = strpos($mdContent, '| Compiler');
$tableOld   = substr($mdContent, $tableStart);

$newTable = "| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |" . PHP_EOL;
$newTable .= "|------------|-------------|---------------|-------------|" . PHP_EOL;
$newTable .= $tableData;

$mdContent   = str_replace($tableOld, $newTable, $mdContent);
$scssContent = file_get_contents('generated.scss');
$mdContent .= "\n*Note: These results are approximate. Run `php benchmark.php` from the project root to see the actual results.*\n";

file_put_contents('benchmark.md', $mdContent);
