<?php

declare(strict_types=1);

namespace Bugo\Sass;

/**
 * Shared `sourceMapPath` policy for both compilers.
 *
 * Neither the CLI nor the embedded protocol decides where a source map ends up:
 * the CLI can only hand back a map embedded in the CSS, and the protocol
 * explicitly leaves the `sourceMappingURL` comment (and the map's `file` key) to
 * the host. Everything below is therefore this bridge's own policy:
 *
 * - `inline` embeds the map as a base64 data URI;
 * - a URL (any scheme) is referenced verbatim and nothing is written to disk;
 * - a directory receives `<name>.css.map`;
 * - any other path is treated as the map file itself.
 *
 * The `file` key is never added, because no entry point knows where the caller
 * will put the CSS. The raw map is always available via `getSourceMap()`.
 */
trait HandlesSourceMaps
{
    protected ?string $sourceMap = null;

    /**
     * Raw JSON source map produced by the most recent compilation, or null if
     * none was requested.
     */
    public function getSourceMap(): ?string
    {
        return $this->sourceMap;
    }

    protected function emitSourceMap(string $css, string $map, ?string $target, bool $compressed, string $name): string
    {
        $this->sourceMap = $map === '' ? null : $map;

        if ($map === '' || ! self::wantsSourceMap($target)) {
            return $css;
        }

        $target = (string) $target;

        if ($target === 'inline') {
            return self::withSourceMapComment($css, 'data:application/json;base64,' . base64_encode($map), $compressed);
        }

        if (self::hasUrlScheme($target)) {
            return self::withSourceMapComment($css, $target, $compressed);
        }

        $path = self::sourceMapPath($target, $name);

        if (file_put_contents($path, $map) === false) {
            throw new Exception("Unable to write the source map to: $path");
        }

        return self::withSourceMapComment($css, basename($path), $compressed);
    }

    protected static function wantsSourceMap(?string $target): bool
    {
        return $target !== null && $target !== '';
    }

    protected static function withSourceMapComment(string $css, string $url, bool $compressed): string
    {
        return $css . ($compressed ? "\n" : "\n\n") . '/*# sourceMappingURL=' . $url . ' */';
    }

    /** Resolves the file the map is written to, expanding a directory target. */
    protected static function sourceMapPath(string $target, string $name): string
    {
        $normalized = str_replace('\\', '/', $target);

        if (! is_dir($target) && ! str_ends_with($normalized, '/')) {
            return $target;
        }

        $base = (string) preg_replace('/\.(?:s[ac]ss|css)$/i', '', basename(str_replace('\\', '/', $name)));

        return rtrim($normalized, '/') . '/' . ($base === '' ? 'style' : $base) . '.css.map';
    }

    /**
     * Whether the value already carries a URL scheme. Single-letter schemes are
     * excluded so that Windows drive letters stay paths.
     */
    protected static function hasUrlScheme(string $value): bool
    {
        return preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]+:#', $value) === 1;
    }
}
