<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\CellPadding;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Table\CellStyle;
use DragonOfMercy\PhpPdf\Table\TableBorders;
use DragonOfMercy\PhpPdf\Table\TableStyle;
use PHPUnit\Framework\TestCase;

final class TableStyleTest extends TestCase
{
    public function testDefaults(): void
    {
        $s = TableStyle::default();
        self::assertSame(TableBorders::GRID, $s->borders);
        self::assertTrue($s->repeatHeader);
        self::assertNull($s->zebraEven);
        self::assertNull($s->zebraOdd);
        self::assertNull($s->cellStyle);
    }

    public function testWithersAreImmutable(): void
    {
        $base = TableStyle::default();
        $noRepeat = $base->withRepeatHeader(false);
        self::assertTrue($base->repeatHeader);
        self::assertFalse($noRepeat->repeatHeader);

        $h = $base->withHeader(fill: Color::gray(238), bold: true, textColor: Color::rgb(0, 0, 0));
        self::assertEquals(Color::gray(238), $h->headerFill);
        self::assertTrue($h->headerBold);

        $z = $base->withZebra(Color::rgb(255, 255, 255), Color::gray(247));
        self::assertEquals(Color::gray(247), $z->zebraOdd);

        $b = $base->withBorder(TableBorders::HORIZONTAL)->withBorderStyle(Border::all()->withWidth(0.3));
        self::assertSame(TableBorders::HORIZONTAL, $b->borders);

        $p = $base->withRowPadding(CellPadding::all(2.0));
        self::assertEquals(CellPadding::all(2.0), $p->rowPadding);
    }

    public function testCellStyleCallbackIsStored(): void
    {
        $fn = static fn (mixed $value, array $row, Column $col): ?CellStyle => null;
        $s = TableStyle::default()->withCellStyle($fn);
        self::assertNotNull($s->cellStyle);
        $callable = $s->cellStyleCallable();
        self::assertNotNull($callable);
        self::assertNull($callable('x', [], Column::of('k')));
    }

    public function testZebraRequiresBothColors(): void
    {
        // both colors are mandatory params, so this is a compile-time guarantee;
        // we assert the pair is stored together.
        $s = TableStyle::default()->withZebra(Color::rgb(255, 255, 255), Color::gray(247));
        self::assertNotNull($s->zebraEven);
        self::assertNotNull($s->zebraOdd);
    }
}
