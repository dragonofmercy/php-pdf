<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\FontEngine;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\Tagging\StructureTree;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use DragonOfMercy\PhpPdf\Tagging\TableScope;
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
     * Active logical-structure tree for the duration of a render(), captured
     * once (it is per-document and stable across page breaks within one render),
     * or null when tagging is off.
     */
    private ?StructureTree $tree = null;

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
        // An artifact scope (e.g. a table drawn from a header/footer) must emit
        // no structure: suppress the tree so TR/TH/TD are not opened onto the
        // wrong parent.
        $this->tree = $page->isArtifactScope() ? null : $page->document()?->structureTree();
        $unitWidth = $page->fromPt($totalWidthPt);
        $widthsUnit = self::distributeWidths($this->columns, $unitWidth);
        $widthsPt = array_map(static fn (float $w): float => $page->toPt($w), $widthsUnit);

        $hasColumnHeader = false;
        foreach ($this->columns as $col) {
            if ($col->header !== null) {
                $hasColumnHeader = true;
                break;
            }
        }
        $groups = $this->style->columnGroups;
        if ($groups !== null) {
            $this->validateGroups($groups);
        }
        $hasHeader = $hasColumnHeader || $groups !== null;

        $rowPaddingPt = $this->rowPaddingPt($page);
        $fontState = $page->captureFontState();
        $baseFont = $page->getFont();
        $baseSize = $page->getFontSize();

        $y = $startYPt;
        $rowCount = 0;
        $pageCount = 1;

        if ($hasHeader) {
            $y = $this->drawHeaderRow($page, $startXPt, $y, $widthsPt, $rowPaddingPt, $baseFont, $baseSize, $fontState);
        }

        $rowIndex = 0;
        foreach ($this->rows as $row) {
            $rowHeightPt = $this->measureRowHeightPt($page, $row, $widthsPt, $rowPaddingPt, $baseFont, $baseSize, $fontState);

            $bottomLimitPt = $page->pageHeight - $page->toPt($page->margins()->bottom);
            if ($this->paginates($page) && ($y + $rowHeightPt) > $bottomLimitPt + Page::OVERFLOW_EPSILON_PT) {
                $document = $page->document();
                if ($document !== null) {
                    $page = $document->addPage();
                    $page->restoreFontState($fontState);
                    $y = $page->toPt($page->margins()->top);
                    $pageCount++;
                    if ($hasHeader && $this->style->repeatHeader) {
                        $y = $this->drawHeaderRow($page, $startXPt, $y, $widthsPt, $rowPaddingPt, $baseFont, $baseSize, $fontState);
                    }
                }
            }

            $this->drawDataRow($page, $row, $startXPt, $y, $widthsPt, $rowHeightPt, $rowPaddingPt, $rowIndex, $baseFont, $baseSize, $fontState);
            $y += $rowHeightPt;
            $rowCount++;
            $rowIndex++;
        }

        $page->restoreFontState($fontState);
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
        return $page->paddingToPt($p);
    }

    private function columnPaddingPt(Page $page, Column $col): ?CellPadding
    {
        if ($col->padding === null) {
            return null;
        }
        return $page->paddingToPt($col->padding);
    }

    /**
     * @param list<float> $widthsPt
     * @param array{font: ?Font, size: ?float, leading: ?float, engine: ?FontEngine} $fontState
     */
    private function drawHeaderRow(Page $page, float $xPt, float $yPt, array $widthsPt, CellPadding $padPt, Font $baseFont, float $baseSize, array $fontState): float
    {
        $groups = $this->style->columnGroups;

        // Per-column header row height (unchanged measurement).
        $headerH = 0.0;
        $i = 0;
        foreach ($this->columns as $col) {
            $colPad = $this->columnPaddingPt($page, $col) ?? $padPt;
            $innerW = max(0.0, $widthsPt[$i] - $colPad->left - $colPad->right);
            $rs = self::resolveCellStyle($col->header ?? '', [], $col, Cell::of($col->header ?? ''), $this->style, -1, true);
            $this->applyFont($page, $baseFont, $baseSize, $rs->bold === true);
            $headerH = max($headerH, $page->tableTextHeightPt($col->header ?? '', $innerW) + $colPad->top + $colPad->bottom);
            $i++;
        }

        // Band height (0 when no groups), measured over labeled groups only.
        $bandH = 0.0;
        if ($groups !== null) {
            $colStart = 0;
            foreach ($groups as $g) {
                if (!$g->isSpacer()) {
                    $mergedW = self::mergedWidthPt($widthsPt, $colStart, $g->span);
                    $innerW = max(0.0, $mergedW - $padPt->left - $padPt->right);
                    $this->applyFont($page, $baseFont, $baseSize, ($g->bold ?? $this->style->headerBold) === true);
                    $bandH = max($bandH, $page->tableTextHeightPt($g->label, $innerW) + $padPt->top + $padPt->bottom);
                }
                $colStart += $g->span;
            }
        }

        $border = $this->borderForCell($page, true);

        // Auto-tagging: open a <TR> for the whole header row; each header cell
        // becomes a <TH> with its text as a marked-content leaf.
        $tree = $this->tree;
        if ($tree !== null) {
            $tree->open(StructureType::TR);
        }

        // Draw labeled band cells at the top band.
        if ($groups !== null && $bandH > 0.0) {
            $cx = $xPt;
            $colStart = 0;
            foreach ($groups as $g) {
                $mergedW = self::mergedWidthPt($widthsPt, $colStart, $g->span);
                if (!$g->isSpacer()) {
                    $this->applyFont($page, $baseFont, $baseSize, ($g->bold ?? $this->style->headerBold) === true);
                    $mcid = $this->beginCell($tree, $page, StructureType::TH, $g->label);
                    $page->drawTableCell($cx, $yPt, $mergedW, $bandH, $g->label, $border, $g->fill ?? $this->style->headerFill, $g->textColor ?? $this->style->headerTextColor, $g->align, VerticalAlign::MIDDLE, $padPt, markedContentId: $mcid, markedContentTag: StructureType::TH->value);
                    $this->endCell($tree, $page, $mcid);
                }
                $cx += $mergedW;
                $colStart += $g->span;
            }
        }

        // Draw per-column headers. Spacer columns rise across band + header.
        $spacerColumns = $this->spacerColumnSet($groups);
        $cx = $xPt;
        $i = 0;
        foreach ($this->columns as $col) {
            $rs = self::resolveCellStyle($col->header ?? '', [], $col, Cell::of($col->header ?? ''), $this->style, -1, true);
            $this->applyFont($page, $baseFont, $baseSize, $rs->bold === true);
            $colPad = $this->columnPaddingPt($page, $col) ?? $padPt;
            $isSpacer = isset($spacerColumns[$i]);
            $cellY = $isSpacer ? $yPt : $yPt + $bandH;
            $cellH = $isSpacer ? $bandH + $headerH : $headerH;
            $headerText = $col->header ?? '';
            $mcid = $this->beginCell($tree, $page, StructureType::TH, $headerText);
            $page->drawTableCell($cx, $cellY, $widthsPt[$i], $cellH, $headerText, $border, $rs->fill, $rs->textColor, $rs->align ?? $col->align, $col->verticalAlign, $colPad, markedContentId: $mcid, markedContentTag: StructureType::TH->value);
            $this->endCell($tree, $page, $mcid);
            $cx += $widthsPt[$i];
            $i++;
        }

        if ($tree !== null) {
            $tree->close();
        }

        $page->restoreFontState($fontState);
        return $yPt + $bandH + $headerH;
    }

    /**
     * @param list<ColumnGroup> $groups
     */
    private function validateGroups(array $groups): void
    {
        $sum = 0;
        foreach ($groups as $g) {
            $sum += $g->span;
        }
        $count = count($this->columns);
        if ($sum !== $count) {
            throw new PdfException('Column groups span ' . $sum . ' columns but the table has ' . $count);
        }
    }

    /**
     * Column indices covered by a spacer group (label === '').
     *
     * @param list<ColumnGroup>|null $groups
     * @return array<int, true>
     */
    private function spacerColumnSet(?array $groups): array
    {
        $set = [];
        if ($groups === null) {
            return $set;
        }
        $colStart = 0;
        foreach ($groups as $g) {
            if ($g->isSpacer()) {
                for ($k = $colStart; $k < $colStart + $g->span; $k++) {
                    $set[$k] = true;
                }
            }
            $colStart += $g->span;
        }
        return $set;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<float> $widthsPt
     * @param array{font: ?Font, size: ?float, leading: ?float, engine: ?FontEngine} $fontState
     */
    private function measureRowHeightPt(Page $page, array $row, array $widthsPt, CellPadding $padPt, Font $baseFont, float $baseSize, array $fontState): float
    {
        $height = 0.0;
        $count = count($this->columns);
        $i = 0;
        while ($i < $count) {
            [$cell, $mergedWidth, $span] = $this->spanAt($row, $widthsPt, $i);
            $col = $this->columns[$i];
            $colPad = $this->columnPaddingPt($page, $col) ?? $padPt;
            $innerW = max(0.0, $mergedWidth - $colPad->left - $colPad->right);
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
            $i += $span;
        }
        $page->restoreFontState($fontState);
        return $height;
    }

    /**
     * Resolve the anchor cell, merged width, and span at column index $i.
     *
     * @param array<string, mixed> $row
     * @param list<float> $widthsPt
     * @return array{0: Cell, 1: float, 2: int}
     */
    private function spanAt(array $row, array $widthsPt, int $i): array
    {
        $col = $this->columns[$i];
        $cell = $this->cellFor($row, $col);
        $span = max(1, $cell->colSpan);
        if ($i + $span > count($this->columns)) {
            throw new PdfException(
                'Cell colSpan ' . $cell->colSpan . " at column '" . $col->key . "' exceeds the table column count (" . count($this->columns) . ')'
            );
        }
        return [$cell, self::mergedWidthPt($widthsPt, $i, $span), $span];
    }

    /**
     * Sum the widths of `$span` columns starting at index `$start`.
     *
     * @param list<float> $widthsPt
     */
    private static function mergedWidthPt(array $widthsPt, int $start, int $span): float
    {
        $width = 0.0;
        for ($k = $start; $k < $start + $span; $k++) {
            $width += $widthsPt[$k];
        }
        return $width;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<float> $widthsPt
     * @param array{font: ?Font, size: ?float, leading: ?float, engine: ?FontEngine} $fontState
     */
    private function drawDataRow(Page $page, array $row, float $xPt, float $yPt, array $widthsPt, float $rowHeightPt, CellPadding $padPt, int $rowIndex, Font $baseFont, float $baseSize, array $fontState): void
    {
        $border = $this->borderForCell($page, false);

        // Auto-tagging: open a <TR> for the whole data row; each cell becomes a
        // <TD>. Text cells carry their text as a marked-content leaf; image
        // cells get an empty <TD> (image-in-table tagging is deferred).
        $tree = $this->tree;
        if ($tree !== null) {
            $tree->open(StructureType::TR);
        }

        $cx = $xPt;
        $count = count($this->columns);
        $i = 0;
        while ($i < $count) {
            [$cell, $mergedWidth, $span] = $this->spanAt($row, $widthsPt, $i);
            $col = $this->columns[$i];
            $value = $row[$col->key] ?? '';
            $rs = self::resolveCellStyle($value, $row, $col, $cell, $this->style, $rowIndex, false);
            $colPad = $this->columnPaddingPt($page, $col) ?? $padPt;

            $image = $cell->image;
            if ($cell->isImage() && $image !== null) {
                if ($tree !== null) {
                    $tree->open(StructureType::TD);
                }
                // Background + border first (empty text), then the image on top.
                $page->drawTableCell($cx, $yPt, $mergedWidth, $rowHeightPt, '', $border, $rs->fill, null, $col->align, $col->verticalAlign, $colPad);
                $reqW = $cell->imageWidth !== null ? $page->toPt($cell->imageWidth) : null;
                $reqH = $cell->imageHeight !== null ? $page->toPt($cell->imageHeight) : null;
                $page->drawTableImage($cx, $yPt, $mergedWidth, $rowHeightPt, $image, $reqW, $reqH, $cell->align ?? TextAlign::CENTER, $cell->verticalAlign ?? VerticalAlign::MIDDLE, $colPad);
                if ($tree !== null) {
                    $tree->close();
                }
            } else {
                $this->applyFont($page, $baseFont, $baseSize, $rs->bold === true);
                $mcid = $this->beginCell($tree, $page, StructureType::TD, $cell->text);
                $page->drawTableCell($cx, $yPt, $mergedWidth, $rowHeightPt, $cell->text, $border, $rs->fill, $rs->textColor, $rs->align ?? $col->align, $cell->verticalAlign ?? $col->verticalAlign, $colPad, markedContentId: $mcid, markedContentTag: StructureType::TD->value, direction: $cell->direction);
                $this->endCell($tree, $page, $mcid);
            }
            $cx += $mergedWidth;
            $i += $span;
        }

        if ($tree !== null) {
            $tree->close();
        }
        $page->restoreFontState($fontState);
    }

    /**
     * Open a TH/TD structure element for one cell and mint an MCID on the
     * emitting page when the cell carries text. Returns the MCID to bracket the
     * cell's text-show operators, or null when there is no tree or no text.
     */
    private function beginCell(?StructureTree $tree, Page $page, StructureType $type, string $text): ?int
    {
        if ($tree === null) {
            return null;
        }
        $elem = $tree->open($type);
        if ($type === StructureType::TH) {
            $elem->setScope(TableScope::Column);
        }
        if ($text === '') {
            return null;
        }
        return $page->nextMcid();
    }

    /**
     * Record the cell's marked-content leaf (when one was minted) and close the
     * TH/TD opened by {@see beginCell()}.
     */
    private function endCell(?StructureTree $tree, Page $page, ?int $mcid): void
    {
        if ($tree === null) {
            return;
        }
        if ($mcid !== null) {
            $tree->addMarkedContent($page->pageIndex(), $mcid);
        }
        $tree->close();
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
