<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\Color;

/**
 * Table-wide presentation and pagination config. Immutable; build from
 * TableStyle::default() with the with* methods.
 *
 * @phpstan-type CellStyleCallback callable(mixed, array<string, mixed>, Column): (CellStyle|null)
 */
final readonly class TableStyle
{
    public function __construct(
        public TableBorders $borders = TableBorders::GRID,
        public ?Border $borderStyle = null,
        public ?Color $headerFill = null,
        public bool $headerBold = true,
        public ?Color $headerTextColor = null,
        public ?Color $zebraEven = null,
        public ?Color $zebraOdd = null,
        public mixed $cellStyle = null,
        public bool $repeatHeader = true,
        public ?CellPadding $rowPadding = null,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withBorder(TableBorders $borders): self
    {
        return $this->copy(borders: $borders);
    }

    public function withBorderStyle(Border $b): self
    {
        return $this->copy(borderStyle: $b);
    }

    public function withHeader(?Color $fill = null, bool $bold = true, ?Color $textColor = null): self
    {
        return $this->copy(headerFill: $fill, headerBold: $bold, headerTextColor: $textColor);
    }

    public function withZebra(Color $even, Color $odd): self
    {
        return $this->copy(zebraEven: $even, zebraOdd: $odd);
    }

    /** @param CellStyleCallback $fn */
    public function withCellStyle(callable $fn): self
    {
        return $this->copy(cellStyle: $fn);
    }

    public function withRepeatHeader(bool $repeat): self
    {
        return $this->copy(repeatHeader: $repeat);
    }

    public function withRowPadding(CellPadding $p): self
    {
        return $this->copy(rowPadding: $p);
    }

    /**
     * Returns the cell-style callback if one is set, or null.
     *
     * @return CellStyleCallback|null
     */
    public function cellStyleCallable(): ?callable
    {
        return is_callable($this->cellStyle) ? $this->cellStyle : null;
    }

    private function copy(
        ?TableBorders $borders = null,
        ?Border $borderStyle = null,
        ?Color $headerFill = null,
        ?bool $headerBold = null,
        ?Color $headerTextColor = null,
        ?Color $zebraEven = null,
        ?Color $zebraOdd = null,
        mixed $cellStyle = null,
        ?bool $repeatHeader = null,
        ?CellPadding $rowPadding = null,
    ): self {
        return new self(
            borders: $borders ?? $this->borders,
            borderStyle: $borderStyle ?? $this->borderStyle,
            headerFill: $headerFill ?? $this->headerFill,
            headerBold: $headerBold ?? $this->headerBold,
            headerTextColor: $headerTextColor ?? $this->headerTextColor,
            zebraEven: $zebraEven ?? $this->zebraEven,
            zebraOdd: $zebraOdd ?? $this->zebraOdd,
            cellStyle: $cellStyle ?? $this->cellStyle,
            repeatHeader: $repeatHeader ?? $this->repeatHeader,
            rowPadding: $rowPadding ?? $this->rowPadding,
        );
    }
}
