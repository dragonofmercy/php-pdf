<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;

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
     * @param list<Column> $columns
     * @param iterable<array<string, mixed>> $rows
     */
    public function __construct(
        private readonly Page $page,
        private readonly array $columns,
        private readonly iterable $rows,
        private readonly TableStyle $style,
    ) {}

    /**
     * Render the whole table starting at (startXPt, startYPt) within
     * totalWidthPt. All work happens in points. Returns
     * [finalYPt, rowCount, pageCount, finalPage]; the final page differs from
     * the initial one once a page break has occurred.
     *
     * @return array{0: float, 1: int, 2: int, 3: Page}
     */
    public function render(float $startXPt, float $startYPt, float $totalWidthPt): array
    {
        $page = $this->page;
        $unitWidth = $page->fromPt($totalWidthPt);
        $widthsUnit = self::distributeWidths($this->columns, $unitWidth);
        $widthsPt = array_map(static fn (float $w): float => $page->toPt($w), $widthsUnit);

        $hasHeader = false;
        foreach ($this->columns as $col) {
            if ($col->header !== null) {
                $hasHeader = true;
                break;
            }
        }

        $rowPaddingPt = $this->rowPaddingPt($page);
        $baseFont = $page->getFont();
        $baseSize = $page->getFontSize();

        $y = $startYPt;
        $rowCount = 0;
        $pageCount = 1;

        if ($hasHeader) {
            $y = $this->drawHeaderRow($page, $startXPt, $y, $widthsPt, $rowPaddingPt, $baseFont, $baseSize);
        }

        $rowIndex = 0;
        foreach ($this->rows as $row) {
            $rowHeightPt = $this->measureRowHeightPt($page, $row, $widthsPt, $rowPaddingPt, $baseFont, $baseSize);

            $bottomLimitPt = $page->pageHeight - $page->toPt($page->margins()->bottom);
            if ($this->paginates($page) && ($y + $rowHeightPt) > $bottomLimitPt + 0.0001) {
                $document = $page->document();
                if ($document !== null) {
                    $page = $document->addPage();
                    $page->setFont($baseFont, $baseSize);
                    $y = $page->toPt($page->margins()->top);
                    $pageCount++;
                    if ($hasHeader && $this->style->repeatHeader) {
                        $y = $this->drawHeaderRow($page, $startXPt, $y, $widthsPt, $rowPaddingPt, $baseFont, $baseSize);
                    }
                }
            }

            $this->drawDataRow($page, $row, $startXPt, $y, $widthsPt, $rowHeightPt, $rowPaddingPt, $rowIndex, $baseFont, $baseSize);
            $y += $rowHeightPt;
            $rowCount++;
            $rowIndex++;
        }

        $page->setFont($baseFont, $baseSize);
        return [$y, $rowCount, $pageCount, $page];
    }

    private function paginates(Page $page): bool
    {
        $doc = $page->document();
        return $doc !== null && $doc->autoPageBreak();
    }

    private function rowPaddingPt(Page $page): CellPadding
    {
        $p = $this->style->rowPadding ?? CellPadding::all($page->fromPt(2.0));
        return new CellPadding(
            $page->toPt($p->top),
            $page->toPt($p->right),
            $page->toPt($p->bottom),
            $page->toPt($p->left),
        );
    }

    private function columnPaddingPt(Page $page, Column $col): ?CellPadding
    {
        if ($col->padding === null) {
            return null;
        }
        $p = $col->padding;
        return new CellPadding(
            $page->toPt($p->top),
            $page->toPt($p->right),
            $page->toPt($p->bottom),
            $page->toPt($p->left),
        );
    }

    /** @param list<float> $widthsPt */
    private function drawHeaderRow(Page $page, float $xPt, float $yPt, array $widthsPt, CellPadding $padPt, Font $baseFont, float $baseSize): float
    {
        $height = 0.0;
        $i = 0;
        foreach ($this->columns as $col) {
            $colPad = $this->columnPaddingPt($page, $col) ?? $padPt;
            $innerW = max(0.0, $widthsPt[$i] - $colPad->left - $colPad->right);
            $rs = self::resolveCellStyle($col->header ?? '', [], $col, Cell::of($col->header ?? ''), $this->style, -1, true);
            $this->applyFont($page, $baseFont, $baseSize, $rs->bold === true);
            $height = max($height, $page->tableTextHeightPt($col->header ?? '', $innerW) + $colPad->top + $colPad->bottom);
            $i++;
        }

        $cx = $xPt;
        $i = 0;
        foreach ($this->columns as $col) {
            $rs = self::resolveCellStyle($col->header ?? '', [], $col, Cell::of($col->header ?? ''), $this->style, -1, true);
            $border = $this->borderForCell($page, true);
            $this->applyFont($page, $baseFont, $baseSize, $rs->bold === true);
            $colPad = $this->columnPaddingPt($page, $col) ?? $padPt;
            $page->drawTableCell($cx, $yPt, $widthsPt[$i], $height, $col->header ?? '', $border, $rs->fill, $rs->textColor, $rs->align ?? $col->align, $col->verticalAlign, $colPad);
            $cx += $widthsPt[$i];
            $i++;
        }

        $page->setFont($baseFont, $baseSize);
        return $yPt + $height;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<float> $widthsPt
     */
    private function measureRowHeightPt(Page $page, array $row, array $widthsPt, CellPadding $padPt, Font $baseFont, float $baseSize): float
    {
        $height = 0.0;
        $i = 0;
        foreach ($this->columns as $col) {
            $cell = $this->cellFor($row, $col);
            $colPad = $this->columnPaddingPt($page, $col) ?? $padPt;
            $innerW = max(0.0, $widthsPt[$i] - $colPad->left - $colPad->right);
            $image = $cell->image;
            if ($cell->isImage() && $image !== null) {
                $reqW = $cell->imageWidth !== null ? $page->toPt($cell->imageWidth) : null;
                $reqH = $cell->imageHeight !== null ? $page->toPt($cell->imageHeight) : null;
                [, $drawH] = $page->resolveTableImageSizePt($image, $reqW, $reqH, $innerW);
                $cellH = $drawH + $colPad->top + $colPad->bottom;
            } else {
                $this->applyFont($page, $baseFont, $baseSize, $cell->bold === true);
                $cellH = $page->tableTextHeightPt($cell->text, $innerW) + $colPad->top + $colPad->bottom;
            }
            $height = max($height, $cellH);
            $i++;
        }
        $page->setFont($baseFont, $baseSize);
        return $height;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<float> $widthsPt
     */
    private function drawDataRow(Page $page, array $row, float $xPt, float $yPt, array $widthsPt, float $rowHeightPt, CellPadding $padPt, int $rowIndex, Font $baseFont, float $baseSize): void
    {
        $cx = $xPt;
        $i = 0;
        foreach ($this->columns as $col) {
            $cell = $this->cellFor($row, $col);
            $value = $row[$col->key] ?? '';
            $rs = self::resolveCellStyle($value, $row, $col, $cell, $this->style, $rowIndex, false);
            $border = $this->borderForCell($page, false);
            $colPad = $this->columnPaddingPt($page, $col) ?? $padPt;

            $image = $cell->image;
            if ($cell->isImage() && $image !== null) {
                // Background + border first (empty text), then the image on top.
                $page->drawTableCell($cx, $yPt, $widthsPt[$i], $rowHeightPt, '', $border, $rs->fill, null, $col->align, $col->verticalAlign, $colPad);
                $reqW = $cell->imageWidth !== null ? $page->toPt($cell->imageWidth) : null;
                $reqH = $cell->imageHeight !== null ? $page->toPt($cell->imageHeight) : null;
                $page->drawTableImage($cx, $yPt, $widthsPt[$i], $rowHeightPt, $image, $reqW, $reqH, $cell->align ?? TextAlign::CENTER, $cell->verticalAlign ?? VerticalAlign::MIDDLE, $colPad);
            } else {
                $this->applyFont($page, $baseFont, $baseSize, $rs->bold === true);
                $page->drawTableCell($cx, $yPt, $widthsPt[$i], $rowHeightPt, $cell->text, $border, $rs->fill, $rs->textColor, $rs->align ?? $col->align, $cell->verticalAlign ?? $col->verticalAlign, $colPad);
            }
            $cx += $widthsPt[$i];
            $i++;
        }
        $page->setFont($baseFont, $baseSize);
    }

    /** @param array<string, mixed> $row */
    private function cellFor(array $row, Column $col): Cell
    {
        $raw = $row[$col->key] ?? '';
        if ($raw instanceof Cell) {
            return $raw;
        }
        if ($raw instanceof \Stringable || is_scalar($raw)) {
            return Cell::of((string) $raw);
        }
        return Cell::of('');
    }

    private function applyFont(Page $page, Font $baseFont, float $baseSize, bool $bold): void
    {
        $page->setFont($bold ? $baseFont->bold() : $baseFont, $baseSize);
    }

    private function borderForCell(Page $page, bool $isHeader): Border
    {
        $base = $this->style->borderStyle ?? Border::all()->withWidth($page->fromPt(0.5));
        $width = $base->width ?? $page->fromPt(0.5);
        return match ($this->style->borders) {
            TableBorders::GRID => $base,
            TableBorders::HORIZONTAL => Border::sides(top: true, bottom: true)->withWidth($width)->withColor($base->color)->withStyle($base->style),
            TableBorders::HEADER_UNDERLINE => $isHeader
                ? Border::sides(bottom: true)->withWidth($width)->withColor($base->color)->withStyle($base->style)
                : Border::none(),
            TableBorders::NONE => Border::none(),
        };
    }

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
