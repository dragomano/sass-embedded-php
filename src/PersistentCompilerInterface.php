<?php declare(strict_types=1);

namespace Bugo\Sass;

interface PersistentCompilerInterface
{
    public function enablePersistentMode(): static;

    public function compileInPersistentMode(string $source, array $options = []): string;

    public function exitPersistentMode(): void;
}
