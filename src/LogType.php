<?php

declare(strict_types=1);

namespace Bugo\Sass;

/**
 * Mirrors `LogEventType` from the embedded protocol.
 */
enum LogType: int
{
    /** Emitted by the `@warn` rule and other non-deprecation warnings. */
    case Warning = 0;

    /** Emitted when a stylesheet uses a deprecated Sass feature. */
    case Deprecation = 1;

    /** Emitted by the `@debug` rule. */
    case Debug = 2;
}
