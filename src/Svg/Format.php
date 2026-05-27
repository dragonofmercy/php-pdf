<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Stable, locale-independent float formatting for PDF content streams and
 * dictionaries. Integral values render without a decimal point; otherwise
 * six decimals with trailing zeros and the trailing dot stripped. Centralized
 * so every SVG emitter produces byte-identical output.
 *
 * @internal
 */
final class Format
{
    public static function num(float $v): string
    {
        if ($v == (int) $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        $s = number_format($v, 6, '.', '');
        $s = rtrim($s, '0');
        return rtrim($s, '.');
    }
}
