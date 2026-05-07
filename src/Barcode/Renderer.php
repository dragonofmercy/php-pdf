<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Page\Operators;

/**
 * Internal helpers shared by every Barcode implementation.
 *
 * Two responsibilities:
 *   - Aggregate runs of dark modules into the smallest set of `re` operators.
 *   - Wrap the rect path in `q ... color ... f Q` so a single fill renders the
 *     whole code and the page's graphics state is unaffected after the call.
 *
 * @internal
 */
final class Renderer
{
    /**
     * Emit one PDF rect per maximal run of `true` in the row.
     *
     * @param list<bool> $row
     */
    public static function runLengthRow(array $row, float $xStart, float $y, float $moduleWidth, float $h): string
    {
        $ops = '';
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
            $runLen = $i - $start;
            $x = $xStart + $start * $moduleWidth;
            $w = $runLen * $moduleWidth;
            $ops .= Operators::rectangle($x, $y, $w, $h);
        }
        return $ops;
    }

    /**
     * Emit rects for every row of a 2D matrix, with horizontal run-length.
     * `yTopDown` is the page-space top of the matrix (the Page applies its
     * Y-down convention; the top of the matrix is at the smallest Y).
     *
     * Each row is `moduleSize` tall and starts at `yTopDown + rowIndex * moduleSize`.
     *
     * @param list<list<bool>> $matrix
     */
    public static function runLengthMatrix(array $matrix, float $xStart, float $yTopDown, float $moduleSize): string
    {
        $ops = '';
        foreach ($matrix as $rowIndex => $row) {
            $y = $yTopDown + $rowIndex * $moduleSize;
            $ops .= self::runLengthRow($row, $xStart, $y, $moduleSize, $moduleSize);
        }
        return $ops;
    }

    /**
     * Wrap a body of `re` operators in q ... color ... f Q so a single fill
     * paints all rects and the page state is restored.
     */
    public static function wrap(string $body, Color $color): string
    {
        return Operators::saveState()
            . $color->toPdfOperator(stroke: false)
            . $body
            . Operators::fill()
            . Operators::restoreState();
    }
}
