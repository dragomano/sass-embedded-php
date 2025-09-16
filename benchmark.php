<?php declare(strict_types=1);

require_once 'vendor/autoload.php';

use Bugo\Sass\Compiler as EmbeddedCompiler;

// Function for generating large random SCSS code
function generateLargeScss(int $numClasses = 100, int $nestedLevels = 3): string
{
    $scss = '';

    // Generate variables with math functions
    $scss .= '$primary-color: #007bff;' . PHP_EOL;
    $scss .= '$secondary-color: #6c757d;' . PHP_EOL;
    $scss .= '$font-size: 14px;' . PHP_EOL;
    $scss .= '$border-radius: 5px;' . PHP_EOL;
    $scss .= '$max-width: max(800px, 50vw);' . PHP_EOL;
    $scss .= '$min-padding: min(10px, 2vw);' . PHP_EOL;
    $scss .= '$clamped-size: clamp(12px, 2.5vw, 20px);' . PHP_EOL;

    for ($i = 0; $i < 20; $i++) {
        $randomVal = rand(-50, 50);
        $scss .= '$var' . $i . ': ' . 'abs(' . $randomVal . 'px);' . PHP_EOL;
        $scss .= '$rounded-var' . $i . ': ' . 'round(' . (rand(0, 100) / 3.14) . ');' . PHP_EOL;
        $scss .= '$ceiled-var' . $i . ': ' . 'ceil(' . (rand(0, 100) / 2.7) . 'px);' . PHP_EOL;
        $scss .= '$floored-var' . $i . ': ' . 'floor(' . (rand(0, 100) / 1.8) . 'px);' . PHP_EOL;
    }

    // Generate functions
    $scss .= '@function calculate-size($base, $multiplier: 1) {' . PHP_EOL;
    $scss .= '  @return $base * $multiplier;' . PHP_EOL;
    $scss .= '}' . PHP_EOL;

    // Generate mixins with color functions
    $scss .= '@mixin flex-center {' . PHP_EOL;
    $scss .= '  display: flex;' . PHP_EOL;
    $scss .= '  justify-content: center;' . PHP_EOL;
    $scss .= '  align-items: center;' . PHP_EOL;
    $scss .= '}' . PHP_EOL;
    $scss .= '@mixin button-style($color) {' . PHP_EOL;
    $scss .= '  background-color: lighten($color, 5%);' . PHP_EOL;
    $scss .= '  border: 1px solid saturate($color, 20%);' . PHP_EOL;
    $scss .= '  border-radius: calc($border-radius + 2px);' . PHP_EOL;
    $scss .= '  padding: max(8px, $min-padding) max(15px, calc($min-padding * 2));' . PHP_EOL;
    $scss .= '  &:hover {' . PHP_EOL;
    $scss .= '    background-color: desaturate($color, 10%);' . PHP_EOL;
    $scss .= '    transform: scale(calc(1.05));' . PHP_EOL;
    $scss .= '  }' . PHP_EOL;
    $scss .= '}' . PHP_EOL;
    $scss .= '@mixin color-variations($base-color) {' . PHP_EOL;
    $scss .= '  .light { color: lighten($base-color, 20%); }' . PHP_EOL;
    $scss .= '  .dark { color: darken($base-color, 15%); }' . PHP_EOL;
    $scss .= '  .saturated { color: saturate($base-color, 30%); }' . PHP_EOL;
    $scss .= '  .desaturated { color: desaturate($base-color, 25%); }' . PHP_EOL;
    $scss .= '  .hue-rotated { filter: hue-rotate(45deg); }' . PHP_EOL;
    $scss .= '}' . PHP_EOL;

    // Generate classes with nesting and conditional directives using color and math functions
    for ($i = 0; $i < $numClasses; $i++) {
        $scss .= '.class-' . $i . ' {' . PHP_EOL;
        $scss .= '  background-color: mix($primary-color, $secondary-color, ' . rand(20, 80) . '%);' . PHP_EOL;
        $scss .= '  font-size: clamp($clamped-size, calculate-size($font-size, ' . (rand(1, 3)) . '), 24px);' . PHP_EOL;
        $scss .= '  padding: max($var' . rand(0, 19) . ', $min-padding);' . PHP_EOL;
        $scss .= '  margin: calc($var' . rand(0, 19) . ' + 5px);' . PHP_EOL;
        $scss .= '  border-radius: $border-radius;' . PHP_EOL;
        $scss .= '  max-width: $max-width;' . PHP_EOL;
        $scss .= '  @include color-variations($primary-color);' . PHP_EOL;

        // Conditional directive
        $randomVal = rand(0, 1);
        $scss .= '  @if ' . $randomVal . ' == 1 {' . PHP_EOL;
        $scss .= '    color: lighten($primary-color, 40%);' . PHP_EOL;
        $scss .= '    text-shadow: 1px 1px 2px rgba(0,0,0,0.3);' . PHP_EOL;
        $scss .= '  } @else {' . PHP_EOL;
        $scss .= '    color: darken($primary-color, 20%);' . PHP_EOL;
        $scss .= '    border: 1px solid saturate($primary-color, 15%);' . PHP_EOL;
        $scss .= '  }' . PHP_EOL;

        // Add nesting with color functions
        for ($level = 1; $level <= $nestedLevels; $level++) {
            $scss .= str_repeat('  ', $level) . '&.nested-' . $level . ' {' . PHP_EOL;
            $scss .= str_repeat('  ', $level + 1) . 'filter: hue-rotate(' . (rand(0, 360)) . 'deg) saturate(' . (100 + rand(-20, 20)) . '%);' . PHP_EOL;
            $scss .= str_repeat('  ', $level + 1) . 'background-color: lighten($secondary-color, ' . rand(10, 30) . '%);' . PHP_EOL;
            $scss .= str_repeat('  ', $level + 1) . '@include flex-center;' . PHP_EOL;
            $scss .= str_repeat('  ', $level + 1) . 'transform: scale(calc(1 + ' . (rand(1, 10) / 100) . '));' . PHP_EOL;
            $scss .= str_repeat('  ', $level) . '}' . PHP_EOL;
        }

        $scss .= '  &:hover {' . PHP_EOL;
        $scss .= '    @include button-style(lighten($primary-color, 10%));' . PHP_EOL;
        $scss .= '  }' . PHP_EOL;

        $scss .= '}' . PHP_EOL;
    }

    // Add @for loops with math and color functions
    $scss .= '@for $i from 1 through 20 {' . PHP_EOL;
    $scss .= '  .for-class-#{$i} {' . PHP_EOL;
    $scss .= '    width: calc(10px * $i);' . PHP_EOL;
    $scss .= '    height: min(50px, calc(20px + $i * 2px));' . PHP_EOL;
    $scss .= '    @include button-style(saturate($secondary-color, calc($i * 2%)));' . PHP_EOL;
    $scss .= '    border-radius: clamp(3px, calc($i * 2px), 15px);' . PHP_EOL;
    $scss .= '    filter: hue-rotate(calc($i * 18deg));' . PHP_EOL;
    $scss .= '  }' . PHP_EOL;
    $scss .= '}' . PHP_EOL;

    // Add @each loop with color functions
    $scss .= '$color-names: red, green, blue, yellow, magenta, cyan;' . PHP_EOL;
    $scss .= '$color-values: #ff0000, #00ff00, #0000ff, #ffff00, #ff00ff, #00ffff;' . PHP_EOL;
    $scss .= '@for $i from 1 through length($color-names) {' . PHP_EOL;
    $scss .= '  $name: nth($color-names, $i);' . PHP_EOL;
    $scss .= '  $color: nth($color-values, $i);' . PHP_EOL;
    $scss .= '  .color-#{$name} {' . PHP_EOL;
    $scss .= '    background-color: lighten($color, 10%);' . PHP_EOL;
    $scss .= '    border: 2px solid saturate($color, 20%);' . PHP_EOL;
    $scss .= '    &:hover {' . PHP_EOL;
    $scss .= '      background-color: desaturate($color, 15%);' . PHP_EOL;
    $scss .= '      transform: rotate(calc(var(--rotation, 0deg) + 5deg));' . PHP_EOL;
    $scss .= '    }' . PHP_EOL;
    $scss .= '  }' . PHP_EOL;
    $scss .= '}' . PHP_EOL;

    // Add @while loop with math functions
    $scss .= '$counter: 1;' . PHP_EOL;
    $scss .= '@while $counter <= 15 {' . PHP_EOL;
    $scss .= '  .while-class-#{$counter} {' . PHP_EOL;
    $scss .= '    opacity: calc(0.1 * $counter);' . PHP_EOL;
    $scss .= '    z-index: $counter;' . PHP_EOL;
    $scss .= '    font-size: max(10px, calc(8px + $counter * 0.5px));' . PHP_EOL;
    $scss .= '  }' . PHP_EOL;
    $scss .= '  $counter: $counter + 1;' . PHP_EOL;
    $scss .= '}' . PHP_EOL;

    return $scss;
}

// Function to format result data
function formatResultData(array $data): array
{
    return [
        'time' => is_numeric($data['time']) ? number_format($data['time'], 4) : $data['time'],
        'size' => is_numeric($data['size']) ? number_format($data['size'], 2) : $data['size'],
        'memory' => is_numeric($data['memory']) ? number_format($data['memory'], 2) : $data['memory'],
    ];
}

// Generate large SCSS code
$scss = generateLargeScss(2000, 4);

// Write SCSS to file in UTF-8
file_put_contents('examples/generated.scss', $scss, LOCK_EX);

// Display message about SCSS generation
echo "Generated SCSS saved to generated.scss\n";
echo "SCSS size: " . strlen($scss) . " bytes\n";

// Array of compilers
$compilers = [
    'bugo/sass-embedded-php' => function() {
        $compiler = new EmbeddedCompiler();
        $compiler->setOptions([
            'sourceMap' => true,
            'sourceFile' => 'generated.scss',
            'sourceMapPath' => 'result.css.map',
            'includeSources' => true
        ]);

        return $compiler;
    },
    'bugo/sass-embedded-php (optimized)' => function() {
        $compiler = new EmbeddedCompiler();
        $compiler->setOptions([
            'sourceMap' => true,
            'sourceFile' => 'generated.scss',
            'sourceMapPath' => 'result-optimized.css.map',
            'includeSources' => true,
            'streamResult' => true // Enable streaming for large results
        ]);

        return $compiler;
    },
];

// Results
$results = [];

foreach ($compilers as $name => $compilerFactory) {
    $start = microtime(true);
    $memStart = memory_get_peak_usage(true);

    try {
        $compiler = $compilerFactory();

        // For optimized version, use generator for large files if available
        if (str_contains($name, 'optimized') && method_exists($compiler, 'compileStringAsGenerator')) {
            $cssParts = [];
            foreach ($compiler->compileStringAsGenerator($scss) as $chunk) {
                $cssParts[] = $chunk;
            }
            $css = implode('', $cssParts);
        } else {
            $css = $compiler->compileString($scss);
        }

        // Handle source maps
        $mapFile = str_contains($name, 'optimized') ? 'result-optimized.css.map' : 'result-bugo-sass-embedded-php.css.map';
        if (file_exists($mapFile)) {
            $map = file_get_contents($mapFile);
        }

        $time = microtime(true) - $start;
        $memEnd = memory_get_peak_usage(true);
        $memUsed = ($memEnd - $memStart) / 1024 / 1024;

        $package = str_replace('/', '-', $name);
        $package = str_replace(['(', ')'], ['', ''], $package); // Remove parentheses for filename
        $package = str_replace(' ', '-', $package); // Replace spaces with dashes
        file_put_contents("examples/result-$package.css", $css, LOCK_EX);
        $cssSize = filesize("examples/result-$package.css") / 1024;

        $results[$name] = ['time' => $time, 'size' => $cssSize, 'memory' => $memUsed];

        echo "Compiler: $name, Time: " . number_format($time, 4) . " sec, Size: " . number_format($cssSize, 2) . " KB, Memory: " . number_format($memUsed, 2) . " MB" . PHP_EOL;
    } catch (Exception $e) {
        echo "General error in $name: " . $e->getMessage() . PHP_EOL;

        $results[$name] = ['time' => 'Error', 'size' => 'N/A', 'memory' => 'N/A'];
    }
}

// Output results in Markdown table format
echo PHP_EOL . '## Performance Comparison with Process Caching' . PHP_EOL;

// Test process caching performance
echo "Testing process caching with multiple compilations..." . PHP_EOL;

$numIterations = 5;
$cacheTestResults = [];

foreach ($compilers as $name => $compilerFactory) {
    $times = [];
    $memories = [];

    for ($i = 0; $i < $numIterations; $i++) {
        $start = microtime(true);
        $memStart = memory_get_peak_usage(true);

        try {
            $compiler = $compilerFactory();
            $css = $compiler->compileString($scss);

            $time = microtime(true) - $start;
            $memEnd = memory_get_peak_usage(true);
            $memUsed = ($memEnd - $memStart) / 1024 / 1024;

            $times[] = $time;
            $memories[] = $memUsed;
        } catch (Exception $e) {
            $times[] = 'Error';
            $memories[] = 'N/A';
        }
    }

    $avgTime = is_numeric($times[0]) ? array_sum($times) / count($times) : 'Error';
    $avgMemory = is_numeric($memories[0]) ? array_sum($memories) / count($memories) : 'N/A';

    $cacheTestResults[$name] = [
        'avg_time' => $avgTime,
        'avg_memory' => $avgMemory,
        'iterations' => $numIterations
    ];

    echo "Cached Compiler: $name, Avg Time: " . (is_numeric($avgTime) ? number_format($avgTime, 4) : $avgTime) . " sec, Avg Memory: " . (is_numeric($avgMemory) ? number_format($avgMemory, 2) : $avgMemory) . " MB" . PHP_EOL;
}

echo PHP_EOL . '## Results' . PHP_EOL;
echo '| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |' . PHP_EOL;
echo '|------------|-------------|---------------|-------------|' . PHP_EOL;

foreach ($results as $name => $data) {
    $formatted = formatResultData($data);
    echo "| $name | {$formatted['time']} | {$formatted['size']} | {$formatted['memory']} |" . PHP_EOL;
}

// Now update the table
$mdContent = file_get_contents('benchmark.md');

// Add caching test results to markdown
$cacheTable = PHP_EOL . '## Process Caching Performance (' . $numIterations . ' iterations)' . PHP_EOL;
$cacheTable .= '| Compiler | Avg Time (sec) | Avg Memory (MB) |' . PHP_EOL;
$cacheTable .= '|------------|----------------|----------------|' . PHP_EOL;

foreach ($cacheTestResults as $name => $data) {
    $timeStr = is_numeric($data['avg_time']) ? number_format($data['avg_time'], 4) : $data['avg_time'];
    $memStr = is_numeric($data['avg_memory']) ? number_format($data['avg_memory'], 2) : $data['avg_memory'];
    $cacheTable .= "| $name | $timeStr | $memStr |" . PHP_EOL;
}

$tableStart = strpos($mdContent, '| Compiler');
$tableOld = substr($mdContent, $tableStart);

$newTable = "| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |\n|------------|-------------|---------------|-------------|\n";
foreach ($results as $name => $data) {
    $formatted = formatResultData($data);
    $newTable .= "| $name | {$formatted['time']} | {$formatted['size']} | {$formatted['memory']} |\n";
}

$mdContent = str_replace($tableOld, $newTable, $mdContent);

// Insert caching table before the note
$notePos = strpos($mdContent, '*Note:');
if ($notePos !== false) {
    $mdContent = substr_replace($mdContent, $cacheTable . PHP_EOL, $notePos, 0);
} else {
    $mdContent .= $cacheTable;
}

$scssContent = file_get_contents('examples/generated.scss');
$mdContent .= "\n## Optimizations Implemented\n\n";
$mdContent .= "- **Process Caching**: Node.js processes are cached and reused to avoid spawning overhead\n";
$mdContent .= "- **Streaming for Large Data**: Input data is processed in chunks to prevent memory exhaustion\n";
$mdContent .= "- **Reduced JSON Overhead**: Only necessary data is transmitted between PHP and Node.js\n";
$mdContent .= "- **Generator Support**: Large compilation results can be processed chunk-by-chunk using generators\n";
$mdContent .= "- **Memory Limits**: Automatic streaming activation for files larger than 1MB\n\n";
$mdContent .= "*Note: These results are approximate. To get actual results, run `php benchmark.php` in the project root.*\n";

file_put_contents('benchmark.md', $mdContent);
