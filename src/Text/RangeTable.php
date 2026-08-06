<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Text;

/**
 * Binary search over a generated Unicode property table: a list of
 * [start, end, value] ranges sorted by start, with disjoint ranges. Backs
 * {@see Bidi\BidiCharacterType} and {@see Arabic\ArabicShaper}, whose source
 * tables both cover a few hundred ranges rather than tens of thousands of
 * individual codepoints.
 *
 * @internal
 */
final class RangeTable
{
    /**
     * The value attached to the range covering the codepoint, or the default
     * when no range does.
     *
     * @param list<array{0: int, 1: int, 2: string}> $ranges
     */
    public static function lookup(array $ranges, int $codepoint, string $default): string
    {
        $lo = 0;
        $hi = count($ranges) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $range = $ranges[$mid];
            if ($codepoint < $range[0]) {
                $hi = $mid - 1;
            } elseif ($codepoint > $range[1]) {
                $lo = $mid + 1;
            } else {
                return $range[2];
            }
        }
        return $default;
    }
}
