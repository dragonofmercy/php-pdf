<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};
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

    /**
     * Run a horizontal barcode draw closure, optionally rotated a quarter turn
     * so the bottom of the horizontal symbol ends up on the left ("bas-gauche").
     *
     * The page content stream operates in Y-down user space (a Y-flip CTM is
     * prepended by ContentStream). In that space the quarter-turn is the matrix
     * [0 1 -1 0] (equivalently Operators::rotate(-90)). The translation maps the
     * horizontal box's bottom-left corner to the caller's top-left anchor
     * (xPt, yPt), giving a visual footprint hPt wide x wPt tall anchored there.
     *
     * @param \Closure(): void $drawHorizontal emits the format's bars + text
     */
    public static function oriented(
        Page $page,
        Orientation $orientation,
        float $xPt,
        float $yPt,
        float $wPt,
        float $hPt,
        \Closure $drawHorizontal,
    ): void {
        // $page is only written in the Vertical branch; required unconditionally for API uniformity.
        if ($orientation === Orientation::Horizontal) {
            $drawHorizontal();
            return;
        }
        $tx = $xPt + $yPt + $hPt;
        $ty = $yPt - $xPt;
        $stream = $page->contentStream();
        $stream->append(Operators::saveState());
        $stream->append(Operators::concatMatrix(0, 1, -1, 0, $tx, $ty));
        // try/finally keeps the outer q/Q balanced even if the closure throws,
        // so a caught exception cannot leave an orphan 'q' in the stream.
        try {
            $drawHorizontal();
        } finally {
            $stream->append(Operators::restoreState());
        }
    }
}
