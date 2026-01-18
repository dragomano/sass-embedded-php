<?php

require_once 'vendor/autoload.php';

use Bugo\Sass\Compiler as EmbeddedCompiler;

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
    $scss .= '  .color-#{"#{$name}"} {' . PHP_EOL;
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

$scss = generateLargeScss(500, 6);

file_put_contents('generated.scss', $scss, LOCK_EX);

echo "Generated SCSS saved to generated.scss\n";
echo "SCSS size: " . strlen($scss) . " bytes\n";

$compilers = [
    'bugo/sass-embedded-php-generator' => function() {
        $compiler = new EmbeddedCompiler();
        $compiler->setOptions([
            'style'         => 'compressed',
            'sourceMap'     => true,
            'sourceFile'    => 'generated.scss',
            'sourceMapPath' => 'result-sass-embedded-php-generator.css.map',
            'streamResult'  => true,
        ]);

        return $compiler;
    },
    'bugo/sass-embedded-php-debug' => function() {
        $compiler = new EmbeddedCompiler();
        $compiler->setOptions([
            'style'         => 'compressed',
            'sourceMap'     => true,
            'sourceFile'    => 'generated.scss',
            'sourceMapPath' => 'result-sass-embedded-php.css.map',
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

    $times = [];
    $css = '';
    $map = null;
    $maxMemDelta = 0;
    $package = str_replace('/', '-', $name);
    $cssMap = "result-$package.css.map";

    try {
        for ($i = 0; $i < $runs; $i++) {
            $memBefore = memory_get_usage();
            $start = hrtime(true);
            $compiler = $compilerFactory();

            if ($name === 'bugo/sass-embedded-php-generator') {
                $css = implode('', iterator_to_array($compiler->compileStringAsGenerator($scss)));
            } else {
                $css = $compiler->compileString($scss);
            }

            if ($i === 0 && file_exists($cssMap)) {
                $map = file_get_contents($cssMap);
            }

            $times[] = (hrtime(true) - $start) / 1e9;
            $memAfter = memory_get_usage();
            $maxMemDelta = max($maxMemDelta, $memAfter - $memBefore);

            unset($compiler, $result);
        }

        sort($times);
        $trim = min(2, intdiv(count($times) - 1, 2));
        for ($j = 0; $j < $trim; $j++) {
            array_shift($times);
            array_pop($times);
        }

        $time = array_sum($times) / count($times);
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
    $timeStr = is_numeric($data['time']) ? number_format($data['time'], 4) : $data['time'];
    $sizeStr = is_numeric($data['size']) ? number_format($data['size'], 2) : $data['size'];
    $memStr  = is_numeric($data['memory']) ? number_format($data['memory'], 2) : $data['memory'];
    $freshData = "| $name | $timeStr | $sizeStr | $memStr |" . PHP_EOL;
    $tableData .= $freshData;
    echo $freshData;
}

// Now update the table in Markdown file
$mdContent  = file_get_contents('benchmark.md');

// Get current OS and PHP version
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    exec('cmd /c ver', $output);
    $verOutput = implode("\n", $output);
    if (preg_match('/\[Version ([\d.]+)]/', $verOutput, $matches)) {
        $build = $matches[1];
        $buildNum = (int)explode('.', $build)[2];
        if ($buildNum >= 22000) {
            $os = 'Windows 11';
        } else {
            $os = 'Windows 10';
        }
        // Determine release version
        if ($buildNum >= 26100) {
            $release = '24H2';
        } elseif ($buildNum >= 22621) {
            $release = '23H2';
        } elseif ($buildNum >= 22000) {
            $release = '21H2';
        } else {
            $release = 'Unknown';
        }
        $os .= ' ' . $release . ' (Build ' . $build . ')';
    } else {
        $os = php_uname('s') . ' ' . php_uname('r');
    }
} else {
    $os = php_uname('s') . ' ' . php_uname('r');
}

$phpVersion = PHP_VERSION;

// Replace OS and PHP version in the content
$mdContent = preg_replace('/- \*\*OS\*\*: .+/', '- **OS**: ' . $os, $mdContent);
$mdContent = preg_replace('/- \*\*PHP version\*\*: .+/', '- **PHP version**: ' . $phpVersion, $mdContent);

$tableStart = strpos($mdContent, '| Compiler');
$tableOld   = substr($mdContent, $tableStart);

$newTable = "| Compiler | Time (sec) | CSS Size (KB) | Memory (MB) |" . PHP_EOL;
$newTable .= "|------------|-------------|---------------|-------------|" . PHP_EOL;
$newTable .= $tableData;

$mdContent   = str_replace($tableOld, $newTable, $mdContent);
$scssContent = file_get_contents('generated.scss');
$mdContent .= "\n*Note: These results are approximate. Run `php benchmark.php` from the project root to see the actual results.*\n";

file_put_contents('benchmark.md', $mdContent);
