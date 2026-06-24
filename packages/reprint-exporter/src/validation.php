<?php

namespace Reprint\Exporter;

use InvalidArgumentException;

/**
 * Validates that an integer falls within the given range, or throws.
 */
function require_int_range(
    string $name,
    int $value,
    int $min,
    int $max
): int {
    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException(
            "{$name} out of range. Expected {$min}-{$max}, got {$value}",
        );
    }
    return $value;
}

/**
 * Validates that a float falls within the given range, or throws.
 */
function require_float_range(
    string $name,
    float $value,
    float $min,
    float $max
): float {
    if ($value < $min || $value > $max) {
        throw new InvalidArgumentException(
            "{$name} out of range. Expected {$min}-{$max}, got {$value}",
        );
    }
    return $value;
}
