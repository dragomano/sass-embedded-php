<?php declare(strict_types=1);

namespace Bugo\Sass;

interface CompilerInterface
{
    public function setOptions(array $options): static;

    public function getOptions(): array;

    public function compileString(string $source, array $options = []): string;

    public function compileFile(string $filePath, array $options = []): string;
}
