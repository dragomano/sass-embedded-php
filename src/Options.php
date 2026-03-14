<?php

declare(strict_types=1);

namespace Bugo\Sass;

readonly class Options
{
    public function __construct(
        public ?string $syntax = null,
        public ?string $style = null,
        public ?bool   $sourceMap = null,
        public ?bool   $includeSources = null,
        public ?array  $loadPaths = null,
        public ?bool   $quietDeps = null,
        public ?array  $silenceDeprecations = null,
        public ?bool   $verbose = null,
        public ?bool   $removeEmptyLines = null,
        public ?string $sourceMapPath = null,
        public ?string $url = null,
        public ?string $sourceFile = null,
        public ?bool   $streamResult = null,
    ) {}
}
