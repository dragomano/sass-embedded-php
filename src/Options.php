<?php

declare(strict_types=1);

namespace Bugo\Sass;

readonly class Options
{
    public function __construct(
        public ?string $syntax = null,
        public ?string $style = null,
        public ?bool $includeSources = null,
        public ?array $loadPaths = null,
        public ?bool $quietDeps = null,
        public ?array $silenceDeprecations = null,
        public ?bool $verbose = null,
        public ?string $sourceMapPath = null,
        public ?string $url = null,
        public ?string $sourceFile = null,
    ) {}

    public function withOverrides(?self $overrides): self
    {
        if ($overrides === null) {
            return $this;
        }

        return new self(
            syntax: $overrides->syntax ?? $this->syntax,
            style: $overrides->style ?? $this->style,
            includeSources: $overrides->includeSources ?? $this->includeSources,
            loadPaths: $overrides->loadPaths ?? $this->loadPaths,
            quietDeps: $overrides->quietDeps ?? $this->quietDeps,
            silenceDeprecations: $overrides->silenceDeprecations ?? $this->silenceDeprecations,
            verbose: $overrides->verbose ?? $this->verbose,
            sourceMapPath: $overrides->sourceMapPath ?? $this->sourceMapPath,
            url: $overrides->url ?? $this->url,
            sourceFile: $overrides->sourceFile ?? $this->sourceFile,
        );
    }
}
