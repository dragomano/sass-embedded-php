<?php

declare(strict_types=1);

/**
 * Downloads the sass-spec suite from GitHub into the project's spec/ directory.
 *
 * Usage:
 *   php scripts/update-spec.php           # fetch latest main
 *   php scripts/update-spec.php main      # explicit branch
 *   php scripts/update-spec.php <sha>     # pin to a specific commit
 *
 * Flags:
 *   --if-missing                          # skip download if spec/ already exists
 */

$ref         = $argv[1] ?? 'main';
$ifMissing   = in_array('--if-missing', $argv, true);
$projectRoot = dirname(__DIR__);
$tempDir     = sys_get_temp_dir() . '/sass-spec-' . bin2hex(random_bytes(8));
$specDir     = $projectRoot . '/spec';
$archive     = $tempDir . '.tar.gz';

if ($ifMissing && is_dir($specDir)) {
    echo "spec/ already exists, skipping download.\n";

    exit(0);
}

echo "Fetching sass-spec ({$ref})...\n";

$url = "https://github.com/sass/sass-spec/archive/{$ref}.tar.gz";

$context = stream_context_create([
    'http' => ['timeout' => 120, 'follow_location' => true],
    'ssl'  => ['verify_peer' => true],
]);

$content = @file_get_contents($url, false, $context);

if ($content === false) {
    echo "Error: failed to download {$url}\n";

    exit(1);
}

file_put_contents($archive, $content);

echo "Extracting...\n";

$phar = new PharData($archive);
$phar->decompress();

$tarPath = substr($archive, 0, -3);

$tar = new PharData($tarPath);
$tar->extractTo($tempDir);

unlink($tarPath);
unlink($archive);

$entries = array_values(array_filter(
    scandir($tempDir),
    static fn(string $e): bool => $e !== '.' && $e !== '..' && is_dir($tempDir . '/' . $e),
));

if (count($entries) !== 1) {
    echo "Error: unexpected archive structure\n";
    removeDirectory($tempDir);

    exit(1);
}

$extractedSpec = $tempDir . '/' . $entries[0] . '/spec';

if (! is_dir($extractedSpec)) {
    echo "Error: spec/ directory not found in archive\n";
    removeDirectory($tempDir);

    exit(1);
}

if (is_dir($specDir)) {
    echo "Removing old spec/...\n";
    removeDirectory($specDir);
}

copyDirectory($extractedSpec, $specDir, ['libsass', 'libsass-*']);
removeDirectory($tempDir);

echo "Done. spec/ updated to {$ref}\n";

function copyDirectory(string $source, string $destination, array $excludePatterns = []): void
{
    mkdir($destination, 0755, true);

    $isExcluded = static function (string $basename) use ($excludePatterns): bool {
        foreach ($excludePatterns as $pattern) {
            if (fnmatch($pattern, $basename)) {
                return true;
            }
        }

        return false;
    };

    $filter = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        static function (SplFileInfo $current) use ($isExcluded): bool {
            return ! $isExcluded($current->getFilename());
        },
    );

    $iterator = new RecursiveIteratorIterator($filter);

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $relativePath = substr($file->getRealPath(), strlen($source) + 1);
        $target       = $destination . '/' . str_replace('\\', '/', $relativePath);

        if ($file->isDir()) {
            mkdir($target, 0755, true);
        } else {
            $parent = dirname($target);

            if (! is_dir($parent)) {
                mkdir($parent, 0755, true);
            }

            copy($file->getRealPath(), $target);
        }
    }
}

function removeDirectory(string $dir): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    rmdir($dir);
}
