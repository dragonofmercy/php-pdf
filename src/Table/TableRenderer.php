<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

use DragonOfMercy\PhpPdf\TextAlign;

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

    /**
     * Resolve the effective style of one cell. Precedence low->high:
     * zebra/header base -> column align -> cellStyle callback -> explicit Cell.
     *
     * @param array<string, mixed> $row
     */
    public static function resolveCellStyle(
        mixed $value,
        array $row,
        Column $column,
        Cell $cell,
        TableStyle $style,
        int $rowIndex,
        bool $isHeader,
    ): CellStyle {
        // Base layer: header style, or zebra fill for data rows.
        if ($isHeader) {
            $fill = $style->headerFill;
            $bold = $style->headerBold;
            $textColor = $style->headerTextColor;
        } else {
            $fill = null;
            if ($style->zebraEven !== null && $style->zebraOdd !== null) {
                $fill = $rowIndex % 2 === 0 ? $style->zebraEven : $style->zebraOdd;
            }
            $bold = null;
            $textColor = null;
        }

        $align = $column->align;

        // Callback layer (data rows only; header style is fixed via withHeader()).
        $callback = $style->cellStyleCallable();
        if (!$isHeader && $callback !== null) {
            $override = $callback($value, $row, $column);
            if ($override instanceof CellStyle) {
                $fill = $override->fill ?? $fill;
                $textColor = $override->textColor ?? $textColor;
                $bold = $override->bold ?? $bold;
                $align = $override->align ?? $align;
            }
        }

        // Explicit Cell VO layer (highest).
        $fill = $cell->fill ?? $fill;
        $textColor = $cell->textColor ?? $textColor;
        $bold = $cell->bold ?? $bold;
        $align = $cell->align ?? $align;

        return new CellStyle(textColor: $textColor, fill: $fill, bold: $bold, align: $align);
    }
}
