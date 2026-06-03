<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

/**
 * Owns table geometry (column widths, row heights), pagination, and style
 * resolution. Drawing is delegated to Page's @internal table helpers so the
 * cell/image pipeline is reused verbatim.
 *
 * @internal
 */
final class TableRenderer
{
    /**
     * Resolve each column to an absolute width (same unit as $totalWidth).
     * Fixed widths are honored as-is; the remainder is split across fill
     * columns pro-rata by weight, floored at 0.
     *
     * @param list<Column> $columns
     * @return list<float>
     */
    public static function distributeWidths(array $columns, float $totalWidth): array
    {
        $fixedSum = 0.0;
        $weightSum = 0;
        foreach ($columns as $col) {
            if ($col->fixedWidth !== null) {
                $fixedSum += $col->fixedWidth;
            } else {
                $weightSum += $col->fillWeight ?? 1;
            }
        }

        $remainder = max(0.0, $totalWidth - $fixedSum);

        $widths = [];
        foreach ($columns as $col) {
            if ($col->fixedWidth !== null) {
                $widths[] = $col->fixedWidth;
            } else {
                $weight = $col->fillWeight ?? 1;
                $widths[] = $weightSum > 0 ? $remainder * $weight / $weightSum : 0.0;
            }
        }

        return $widths;
    }
}
