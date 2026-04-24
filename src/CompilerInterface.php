<?php

declare(strict_types=1);

namespace Bugo\Sass;

interface CompilerInterface
{
    public function setOptions(Options $options): static;

    public function getOptions(): Options;

    public function compileString(string $source, ?Options $options = null): string;

    public function compileFile(string $filePath, ?Options $options = null): string;

    public function compileFileAndSave(string $inputPath, string $outputPath, ?Options $options = null): bool;
}
