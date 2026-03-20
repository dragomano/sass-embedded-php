<?php

declare(strict_types=1);

namespace Bugo\Sass;

interface PersistentCompilerInterface
{
    public function compileInPersistentMode(string $source, ?Options $options = null): string;

    public function enablePersistentMode(): static;

    public function disablePersistentMode(): void;
}
