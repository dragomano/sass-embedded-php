<?php

declare(strict_types=1);

namespace Bugo\Sass;

/**
 * A `@warn`, `@debug` or deprecation message reported by the compiler while
 * compiling a stylesheet.
 */
readonly class LogEvent
{
    public function __construct(
        public LogType $type,
        public string $message,
        public string $formatted = '',
        public ?string $deprecationType = null,
        public string $stackTrace = '',
    ) {}
}
