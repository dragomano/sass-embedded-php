<?php

declare(strict_types=1);

namespace Bugo\Sass;

use Generator;

interface CompilerInterface
{
    public function setOptions(Options|array $options): static;

    public function getOptions(): Options;

    public function compileString(string $source, array $options = []): string;

    public function compileFile(string $filePath, array $options = []): string;

    public function compileFileAndSave(string $inputPath, string $outputPath, array $options = []): bool;

    public function compileStringAsGenerator(string $source, array $options = []): Generator;
}
