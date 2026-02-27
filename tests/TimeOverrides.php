<?php

declare(strict_types=1);

namespace Bugo\Sass;

final class TimeOverrides
{
    public static bool $enabled = false;

    /** @var array<int, float> */
    public static array $values = [];
}

function microtime(bool $as_float = false): string|float
{
    if (TimeOverrides::$enabled && TimeOverrides::$values !== []) {
        $value = array_shift(TimeOverrides::$values);

        return $as_float ? $value : (string) $value;
    }

    return \microtime($as_float);
}

function usleep(int $microseconds): void
{
    if (TimeOverrides::$enabled) {
        return;
    }

    \usleep($microseconds);
}
