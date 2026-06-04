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
    /**
     * @param list<ColumnGroup>|null $columnGroups
     */
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
        public ?array $columnGroups = null,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withBorder(TableBorders $borders): self
    {
        return new self(borders: $borders, borderStyle: $this->borderStyle, headerFill: $this->headerFill, headerBold: $this->headerBold, headerTextColor: $this->headerTextColor, zebraEven: $this->zebraEven, zebraOdd: $this->zebraOdd, cellStyle: $this->cellStyle, repeatHeader: $this->repeatHeader, rowPadding: $this->rowPadding, columnGroups: $this->columnGroups);
    }

    public function withBorderStyle(Border $b): self
    {
        return new self(borders: $this->borders, borderStyle: $b, headerFill: $this->headerFill, headerBold: $this->headerBold, headerTextColor: $this->headerTextColor, zebraEven: $this->zebraEven, zebraOdd: $this->zebraOdd, cellStyle: $this->cellStyle, repeatHeader: $this->repeatHeader, rowPadding: $this->rowPadding, columnGroups: $this->columnGroups);
    }

    public function withHeader(?Color $fill = null, bool $bold = true, ?Color $textColor = null): self
    {
        return new self(borders: $this->borders, borderStyle: $this->borderStyle, headerFill: $fill, headerBold: $bold, headerTextColor: $textColor, zebraEven: $this->zebraEven, zebraOdd: $this->zebraOdd, cellStyle: $this->cellStyle, repeatHeader: $this->repeatHeader, rowPadding: $this->rowPadding, columnGroups: $this->columnGroups);
    }

    public function withZebra(Color $even, Color $odd): self
    {
        return new self(borders: $this->borders, borderStyle: $this->borderStyle, headerFill: $this->headerFill, headerBold: $this->headerBold, headerTextColor: $this->headerTextColor, zebraEven: $even, zebraOdd: $odd, cellStyle: $this->cellStyle, repeatHeader: $this->repeatHeader, rowPadding: $this->rowPadding, columnGroups: $this->columnGroups);
    }

    /** @param CellStyleCallback $fn */
    public function withCellStyle(callable $fn): self
    {
        return new self(borders: $this->borders, borderStyle: $this->borderStyle, headerFill: $this->headerFill, headerBold: $this->headerBold, headerTextColor: $this->headerTextColor, zebraEven: $this->zebraEven, zebraOdd: $this->zebraOdd, cellStyle: $fn, repeatHeader: $this->repeatHeader, rowPadding: $this->rowPadding, columnGroups: $this->columnGroups);
    }

    public function withRepeatHeader(bool $repeat): self
    {
        return new self(borders: $this->borders, borderStyle: $this->borderStyle, headerFill: $this->headerFill, headerBold: $this->headerBold, headerTextColor: $this->headerTextColor, zebraEven: $this->zebraEven, zebraOdd: $this->zebraOdd, cellStyle: $this->cellStyle, repeatHeader: $repeat, rowPadding: $this->rowPadding, columnGroups: $this->columnGroups);
    }

    public function withRowPadding(CellPadding $p): self
    {
        return new self(borders: $this->borders, borderStyle: $this->borderStyle, headerFill: $this->headerFill, headerBold: $this->headerBold, headerTextColor: $this->headerTextColor, zebraEven: $this->zebraEven, zebraOdd: $this->zebraOdd, cellStyle: $this->cellStyle, repeatHeader: $this->repeatHeader, rowPadding: $p, columnGroups: $this->columnGroups);
    }

    public function withColumnGroups(ColumnGroup ...$groups): self
    {
        return new self(borders: $this->borders, borderStyle: $this->borderStyle, headerFill: $this->headerFill, headerBold: $this->headerBold, headerTextColor: $this->headerTextColor, zebraEven: $this->zebraEven, zebraOdd: $this->zebraOdd, cellStyle: $this->cellStyle, repeatHeader: $this->repeatHeader, rowPadding: $this->rowPadding, columnGroups: $groups === [] ? null : array_values($groups));
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
}
