<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * Coalesces maximal runs of `true` in a boolean row. Used by both the PDF
 * renderer (one PDF `re` per run) and the SVG renderer (one `<rect>` per run)
 * to keep output compact.
 *
 * @internal
 */
final class RunLength
{
    /**
     * @param list<bool> $row
     * @return list<array{0: int, 1: int}> list of (startIndex, length) tuples for each run of true
     */
    public static function runLengths(array $row): array
    {
        $out = [];
        $n = count($row);
        $i = 0;
        while ($i < $n) {
            if (!$row[$i]) {
                $i++;
                continue;
            }
            $start = $i;
            while ($i < $n && $row[$i]) {
                $i++;
            }
            $out[] = [$start, $i - $start];
        }
        return $out;
    }
}
