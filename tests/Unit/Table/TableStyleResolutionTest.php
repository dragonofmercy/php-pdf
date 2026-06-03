<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\Table\Cell;
use DragonOfMercy\PhpPdf\Table\CellStyle;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Table\TableRenderer;
use DragonOfMercy\PhpPdf\Table\TableStyle;
use PHPUnit\Framework\TestCase;

final class TableStyleResolutionTest extends TestCase
{
    public function testZebraFillIsBase(): void
    {
        $style = TableStyle::default()->withZebra(Color::rgb(255, 255, 255), Color::gray(247));
        $col = Column::of('k');
        // odd row -> zebraOdd
        $r = TableRenderer::resolveCellStyle('v', ['k' => 'v'], $col, Cell::of('v'), $style, rowIndex: 1, isHeader: false);
        self::assertEquals(Color::gray(247), $r->fill);
        self::assertSame(TextAlign::LEFT, $r->align); // from column default
    }

    public function testColumnAlignApplies(): void
    {
        $style = TableStyle::default();
        $col = Column::of('k')->align(TextAlign::RIGHT);
        $r = TableRenderer::resolveCellStyle('v', [], $col, Cell::of('v'), $style, rowIndex: 0, isHeader: false);
        self::assertSame(TextAlign::RIGHT, $r->align);
    }

    public function testCallbackOverridesColumn(): void
    {
        $style = TableStyle::default()->withCellStyle(
            static fn (mixed $value, array $row, Column $c): ?CellStyle =>
                $value === '-5' ? CellStyle::new()->withTextColor(Color::rgb(255, 0, 0)) : null
        );
        $col = Column::of('amount');
        $r = TableRenderer::resolveCellStyle('-5', ['amount' => '-5'], $col, Cell::of('-5'), $style, rowIndex: 0, isHeader: false);
        self::assertEquals(Color::rgb(255, 0, 0), $r->textColor);
    }

    public function testExplicitCellWins(): void
    {
        $style = TableStyle::default()->withCellStyle(
            static fn (mixed $value, array $row, Column $c): CellStyle =>
                CellStyle::new()->withAlign(TextAlign::LEFT)
        );
        $col = Column::of('k')->align(TextAlign::CENTER);
        $cell = Cell::of('x')->align(TextAlign::RIGHT)->bold();
        $r = TableRenderer::resolveCellStyle('x', [], $col, $cell, $style, rowIndex: 0, isHeader: false);
        self::assertSame(TextAlign::RIGHT, $r->align); // explicit cell beats callback + column
        self::assertTrue($r->bold);
    }

    public function testHeaderUsesHeaderStyle(): void
    {
        $style = TableStyle::default()->withHeader(fill: Color::gray(238), bold: true, textColor: Color::rgb(10, 10, 10));
        $col = Column::of('k', 'Header')->align(TextAlign::RIGHT);
        $r = TableRenderer::resolveCellStyle('Header', [], $col, Cell::of('Header'), $style, rowIndex: -1, isHeader: true);
        self::assertEquals(Color::gray(238), $r->fill);
        self::assertTrue($r->bold);
        self::assertEquals(Color::rgb(10, 10, 10), $r->textColor);
        self::assertSame(TextAlign::RIGHT, $r->align); // header keeps column alignment
    }
}
