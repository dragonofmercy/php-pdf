<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Pdf417;

/**
 * Turns an {@see EncodeResult} codeword grid into module rows.
 *
 * For each row y (cluster = y mod 3) the row is built as: the start pattern,
 * the left row-indicator codeword pattern, the data/EC codeword patterns, the
 * right row-indicator codeword pattern, and the stop pattern. Patterns are read
 * MSB-first; a set bit is a dark module.
 *
 * The indicator formulas and the low-level row layout are ported from the zxing
 * PDF417.encodeLowLevel / encodeChar (Apache 2.0).
 *
 * @internal
 */
final class Matrix
{
    /**
     * Build the module rows for the given symbol.
     *
     * @return list<list<bool>> one inner list per row, each a flat list of module booleans
     */
    public static function build(EncodeResult $result): array
    {
        $columns = $result->columns;
        $rows    = $result->rows;
        $e       = $result->ecLevel;
        $cw      = $result->codewords;

        $matrix = [];
        for ($y = 0; $y < $rows; $y++) {
            $cluster  = $y % 3;
            $patterns = CodewordTable::patterns($cluster);

            if ($cluster === 0) {
                $left  = 30 * intdiv($y, 3) + intdiv($rows - 1, 3);
                $right = 30 * intdiv($y, 3) + ($columns - 1);
            } elseif ($cluster === 1) {
                $left  = 30 * intdiv($y, 3) + $e * 3 + (($rows - 1) % 3);
                $right = 30 * intdiv($y, 3) + intdiv($rows - 1, 3);
            } else {
                $left  = 30 * intdiv($y, 3) + ($columns - 1);
                $right = 30 * intdiv($y, 3) + $e * 3 + (($rows - 1) % 3);
            }

            $row = self::bitsOf(CodewordTable::START_PATTERN, 17);

            foreach (self::bitsOf($patterns[$left], 17) as $bit) {
                $row[] = $bit;
            }

            for ($x = 0; $x < $columns; $x++) {
                foreach (self::bitsOf($patterns[$cw[$y * $columns + $x]], 17) as $bit) {
                    $row[] = $bit;
                }
            }

            foreach (self::bitsOf($patterns[$right], 17) as $bit) {
                $row[] = $bit;
            }

            foreach (self::bitsOf(CodewordTable::STOP_PATTERN, 18) as $bit) {
                $row[] = $bit;
            }

            $matrix[] = $row;
        }

        return $matrix;
    }

    /**
     * Module width of one rendered row, excluding the quiet zone:
     * start(17) + leftIndicator(17) + columns*17 + rightIndicator(17) + stop(18).
     */
    public static function modulesPerRow(int $columns): int
    {
        return 17 * ($columns + 3) + 18;
    }

    /** @return list<bool> */
    private static function bitsOf(int $pattern, int $width): array
    {
        $bits = [];
        for ($i = $width - 1; $i >= 0; $i--) {
            $bits[] = (($pattern >> $i) & 1) === 1;
        }
        return $bits;
    }
}
