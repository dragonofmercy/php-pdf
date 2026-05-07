<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * User-facing measurement unit for coordinates and sizes. Internally PDF
 * always works in points; values entering the public API are converted via
 * {@see toPoints()} and values returning to the user via {@see fromPoints()}.
 *
 * Font sizes and leading remain in points regardless of the document unit
 * (typographic convention).
 */
enum Unit
{
    case MM;
    case PT;

    private const float MM_PER_INCH = 25.4;
    private const float PT_PER_INCH = 72.0;

    public function toPoints(float $value): float
    {
        return match ($this) {
            self::PT => $value,
            self::MM => $value * self::PT_PER_INCH / self::MM_PER_INCH,
        };
    }

    public function fromPoints(float $points): float
    {
        return match ($this) {
            self::PT => $points,
            self::MM => $points * self::MM_PER_INCH / self::PT_PER_INCH,
        };
    }
}
