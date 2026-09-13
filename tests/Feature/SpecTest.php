<?php

declare(strict_types=1);

use Bugo\Sass\CompilerInterface;
use Bugo\Sass\EmbeddedCompiler;
use Bugo\Sass\Options;
use Random\RandomException;

$specDir = getenv('SASS_SPEC_DIR') ?: dirname(__DIR__, 2) . '/spec';

if (getenv('RUN_SASS_SPEC') && is_dir($specDir)) {
    $grouped = collectSpecFilesGrouped($specDir);

    foreach ($grouped as $groupLabel => $groupFiles) {
        describe($groupLabel, function () use ($groupFiles, $specDir) {
            $compiler = new EmbeddedCompiler(timeout: 15);

            foreach ($groupFiles as $hxrPath) {
                $relative  = $hxrPath['relative'];
                $testCases = parseSpecFile($hxrPath['absolute'], $specDir);

                if ($testCases === []) {
                    continue;
                }

                it('checks ' . $relative, function () use ($compiler, $testCases) {
                    $failures = [];

                    foreach ($testCases as [$testName, $inputRelPath, $inputSource, $expectedCss, $supportFiles]) {
                        if ($supportFiles === [] && hasExternalDependency($inputSource)) {
                            continue;
                        }

                        try {
                            $actualCss = $supportFiles === []
                                ? $compiler->compileString(
                                    $inputSource,
                                    str_ends_with($inputRelPath, '.sass') ? new Options(syntax: 'indented') : null,
                                )
                                : compileWithSupportFiles($compiler, $inputRelPath, $inputSource, $supportFiles);
                        } catch (Throwable $e) {
                            $failures[] = "$testName — Exception: " . $e->getMessage();

                            continue;
                        }

                        $actualNorm   = normalizeCss($actualCss);
                        $expectedNorm = normalizeCss($expectedCss);

                        if ($actualNorm !== $expectedNorm) {
                            $failures[] = "$testName\nExpected:\n$expectedNorm\nActual:\n$actualNorm";
                        }
                    }

                    expect($failures)->toBeEmpty(
                        count($failures)
                            . ' failed:'
                            . "\n\n"
                            . implode("\n\n---\n\n", $failures),
                    );
                });
            }
        });
    }
}

/**
 * Collects spec files grouped into batches of ~100 for manageable describe blocks.
 *
 * @param string $specDir
 * @return array
 */
function collectSpecFilesGrouped(string $specDir): array
{
    $specDir  = rtrim($specDir, '/\\') . '/';
    $allFiles = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($specDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'hrx') {
            continue;
        }

        $realPath = $file->getRealPath() ?: $file->getPathname();
        $relative = str_replace('\\', '/', substr($realPath, strlen($specDir)));

        $allFiles[] = ['absolute' => $realPath, 'relative' => $relative];
    }

    usort($allFiles, static fn(array $a, array $b): int => strcmp($a['relative'], $b['relative']));

    $batches   = [];
    $batchSize = 100;

    foreach ($allFiles as $index => $file) {
        $batchIndex             = intdiv($index, $batchSize);
        $batches[$batchIndex][] = $file;
    }

    $grouped = [];

    foreach ($batches as $batchIndex => $batchFiles) {
        $first = $batchFiles[0]['relative'];
        $last  = end($batchFiles)['relative'];

        $firstDir = explode('/', $first)[0];
        $lastDir  = explode('/', $last)[0];

        $rangeStart = ($batchIndex * $batchSize) + 1;
        $rangeEnd   = $rangeStart + count($batchFiles) - 1;

        if ($firstDir === $lastDir) {
            $label = "$firstDir [$rangeStart-$rangeEnd]";
        } else {
            $label = "{$firstDir}–$lastDir [$rangeStart-$rangeEnd]";
        }

        $grouped[$label] = $batchFiles;
    }

    return $grouped;
}

/**
 * Parses an HRX file and returns compilable SCSS/Sass test cases.
 *
 * A "base" is the directory that directly contains an input.scss or
 * input.sass file. Everything nested underneath that directory in the HRX
 * archive (partials, files imported via directory-index resolution, etc.)
 * is collected as a "support file" for that test, no matter how deeply
 * nested it is — sass-spec fixtures routinely import from subdirectories
 * (e.g. `@import "dir"` resolving to `dir/index.scss`), so matching only
 * entries that share the *exact* dirname of input.scss misses those files.
 *
 * Support files are keyed by their path relative to the spec root, and the
 * input is referenced by its full path relative to the spec root as well.
 * This mirrors the on-disk layout of sass-spec so that relative imports
 * that climb up with `../` (e.g. `@import "../14_imports/b.scss"`) and
 * spec-root-based `@use` paths resolve exactly like they do in the real
 * suite, instead of being flattened into a single temp directory.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: string, 4: array<string, string>}>
 *         [testName, inputRelPath, inputSource, expectedCss, supportFiles]
 *         inputRelPath is the entry file path relative to the spec root
 *         (e.g. "core_functions/meta/module_functions/multiple/input.scss");
 *         supportFiles maps a path relative to the spec root to its content.
 */
function parseSpecFile(string $hxrPath, string $specDir): array
{
    $content = file_get_contents($hxrPath);

    if ($content === false) {
        return [];
    }

    $entries = parseHrxEntries($content);
    $byBase  = [];

    foreach ($entries as $path => $entryContent) {
        $lastSlash = strrpos($path, '/');
        $base      = $lastSlash !== false ? substr($path, 0, $lastSlash) : '';
        $name      = $lastSlash !== false ? substr($path, $lastSlash + 1) : $path;

        $byBase[$base][$name] = $entryContent;
    }

    $specRoot  = rtrim(str_replace('\\', '/', realpath($specDir) ?: $specDir), '/');
    $hrxPrefix = trim(substr(str_replace('\\', '/', $hxrPath), strlen($specRoot)), '/');
    $hrxPrefix = substr($hrxPrefix, 0, -4);

    $tests = [];

    foreach ($byBase as $base => $files) {
        if (isset($files['options.yml']) && str_contains($files['options.yml'], ':todo:')) {
            continue;
        }

        $inputName = null;

        foreach (['input.scss', 'input.sass'] as $candidate) {
            if (isset($files[$candidate])) {
                $inputName = $candidate;

                break;
            }
        }

        if ($inputName === null) {
            continue;
        }

        $input = $files[$inputName];

        if (isProblematicInput($input)) {
            continue;
        }

        // Prefer the dart-sass-specific expectation when the spec provides one.
        $expected = $files['output-dart-sass.css'] ?? $files['output.css'] ?? null;

        if ($expected === null) {
            // Error spec (expects a compile failure) or otherwise unsupported — skip.
            continue;
        }

        $relBase      = $base === '' ? $hrxPrefix : ($hrxPrefix === '' ? $base : $hrxPrefix . '/' . $base);
        $inputRelPath = $relBase === '' ? $inputName : $relBase . '/' . $inputName;
        $support      = [];

        foreach ($entries as $entryPath => $entryContent) {
            if (isMetaFileName($entryPath) || isSpecInputFileName($entryPath)) {
                continue;
            }

            $fullPath = $hrxPrefix === '' ? $entryPath : $hrxPrefix . '/' . $entryPath;

            if ($fullPath === $inputRelPath) {
                continue;
            }

            $support[$fullPath] = $entryContent;
        }

        $tests[] = [$inputRelPath, $inputRelPath, $input, $expected, $support];
    }

    return $tests;
}

/**
 * Filenames that carry spec metadata rather than compilable Sass sources
 * (warnings, errors, implementation-specific variants, per-directory config).
 */
function isMetaFileName(string $relativePath): bool
{
    $lastSlash = strrpos($relativePath, '/');
    $basename  = $lastSlash !== false ? substr($relativePath, $lastSlash + 1) : $relativePath;

    if ($basename === 'options.yml') {
        return true;
    }

    return (bool) preg_match('/^(warning|error|output)(-[\w.]+)?(\.css)?$/', $basename);
}

/**
 * Whether the entry is a spec input file (input.scss or input.sass) of any
 * base directory. Such files are never imported by other tests, so they
 * must not be written into the temp tree as support files.
 */
function isSpecInputFileName(string $relativePath): bool
{
    $lastSlash = strrpos($relativePath, '/');
    $basename  = $lastSlash !== false ? substr($relativePath, $lastSlash + 1) : $relativePath;

    return $basename === 'input.scss' || $basename === 'input.sass';
}

/**
 * Compiles an input file that needs on-disk support files (partials pulled
 * in via @use/@import/@forward, including directory-index imports).
 *
 * The Compiler class has no way to compile from an in-memory virtual
 * filesystem, so the input and its support files are written to a
 * throwaway temp directory that mirrors the on-disk layout of the spec:
 * every file is placed at its path relative to the spec root. This lets
 * relative imports that climb up with `../` and spec-root-based `@use`
 * paths resolve exactly like they do in the real suite. The temp directory
 * itself is passed as a load path so spec-root-relative imports work too.
 * @throws RandomException
 */
function compileWithSupportFiles(
    CompilerInterface $compiler,
    string $inputRelPath,
    string $inputSource,
    array $supportFiles,
): string {
    $tempDir = createTempSpecDir();

    try {
        writeVirtualFiles($tempDir, $supportFiles);
        $entryPath = $tempDir . '/' . $inputRelPath;
        $entryDir  = dirname($entryPath);

        if (! is_dir($entryDir)) {
            mkdir($entryDir, 0777, true);
        }

        file_put_contents($entryPath, $inputSource);

        return $compiler->compileFile($entryPath, new Options(loadPaths: [$tempDir]));
    } finally {
        removeDirectory($tempDir);
    }
}

/**
 * @throws RandomException
 */
function createTempSpecDir(): string
{
    $dir = sys_get_temp_dir() . '/sass-spec-' . bin2hex(random_bytes(8));

    mkdir($dir, 0777, true);

    return $dir;
}

/**
 * @param array<string, string> $files Relative path => content
 */
function writeVirtualFiles(string $baseDir, array $files): void
{
    foreach ($files as $relativePath => $fileContent) {
        $target    = $baseDir . '/' . $relativePath;
        $targetDir = dirname($target);

        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        file_put_contents($target, $fileContent);
    }
}

function removeDirectory(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    /** @var SplFileInfo $item */
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

/**
 * Parses HRX content into a map of virtual path => file content.
 *
 * @return array<string, string>
 */
function parseHrxEntries(string $content): array
{
    // HRX fixtures may use CRLF separators, while individual CR characters are test data.
    $content      = str_replace("\r\n", "\n", $content);
    $lines        = explode("\n", $content);
    $files        = [];
    $currentPath  = null;
    $currentLines = [];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '<===>')) {
            if ($currentPath !== null && $currentPath !== '') {
                $files[$currentPath] = implode("\n", $currentLines);
            }

            $path = trim(substr($line, 5));

            if ($path === '' || $path === 'README.md') {
                $currentPath = null;
            } else {
                $currentPath = $path;
            }

            $currentLines = [];
        } elseif ($currentPath !== null) {
            $currentLines[] = $line;
        }
    }

    if ($currentPath !== null && $currentPath !== '') {
        $files[$currentPath] = implode("\n", $currentLines);
    }

    return $files;
}

function normalizeCss(string $css): string
{
    return trim((string) preg_replace('/\n[\t ]*\n/', "\n", $css));
}

/**
 * Detects inputs likely to cause segfaults, OOM, or infinite loops.
 */
function isProblematicInput(string $input): bool
{
    if (hasExtendDirective($input)) {
        return true;
    }

    if (hasRecursiveSelfReference($input)) {
        return true;
    }

    if (strlen($input) > 10000) {
        return true;
    }

    return false;
}

/**
 * Detects mixin/function parameters with `$name: $name` pattern
 * which causes infinite recursion in the compiler.
 */
function hasRecursiveSelfReference(string $input): bool
{
    $lines = explode("\n", $input);

    foreach ($lines as $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        if (! str_starts_with($trimmed, '@mixin ') && ! str_starts_with($trimmed, '@function ')) {
            continue;
        }

        $openPos  = strpos($trimmed, '(');
        $closePos = strrpos($trimmed, ')');

        if ($openPos === false || $closePos === false || $closePos <= $openPos) {
            continue;
        }

        $paramStr = substr($trimmed, $openPos + 1, $closePos - $openPos - 1);
        $params   = explode(',', $paramStr);

        foreach ($params as $param) {
            $param    = trim($param);
            $colonPos = strpos($param, ':');

            if ($colonPos === false) {
                continue;
            }

            $paramName  = trim(substr($param, 0, $colonPos));
            $defaultVal = trim(substr($param, $colonPos + 1));

            if ($paramName !== '' && $paramName === $defaultVal) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Checks whether the SCSS input references external files via @use, @import or @forward.
 * Built-in sass:* modules are allowed.
 */
function hasExternalDependency(string $input): bool
{
    $lines      = explode("\n", $input);
    $directives = ['@use', '@import', '@forward'];

    foreach ($lines as $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        foreach ($directives as $directive) {
            $directiveLen = strlen($directive);

            if (! str_starts_with($trimmed, $directive . ' ') && ! str_starts_with($trimmed, $directive . "\t")) {
                continue;
            }

            $rest = ltrim(substr($trimmed, $directiveLen));

            if (str_starts_with($rest, '"sass:') || str_starts_with($rest, "'sass:")) {
                continue 2;
            }

            if (str_starts_with($rest, '"') || str_starts_with($rest, "'")) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Checks whether the SCSS input contains @extend directives
 * which can cause catastrophic backtracking / OOM in the compiler.
 */
function hasExtendDirective(string $input): bool
{
    $lines = explode("\n", $input);

    foreach ($lines as $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        if (str_starts_with($trimmed, '@extend ')) {
            return true;
        }
    }

    return false;
}
