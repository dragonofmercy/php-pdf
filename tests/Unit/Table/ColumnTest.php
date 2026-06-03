<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;
use DragonOfMercy\PhpPdf\CellPadding;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function testFixedWidthColumn(): void
    {
        $c = Column::of('price', 'Prix')->width(30.0)->align(TextAlign::RIGHT);
        self::assertSame('price', $c->key);
        self::assertSame('Prix', $c->header);
        self::assertSame(30.0, $c->fixedWidth);
        self::assertNull($c->fillWeight);
        self::assertSame(TextAlign::RIGHT, $c->align);
    }

    public function testDefaultsToFillWeightOne(): void
    {
        $c = Column::of('name');
        self::assertNull($c->header);
        self::assertNull($c->fixedWidth);
        self::assertSame(1, $c->fillWeight);
        self::assertSame(TextAlign::LEFT, $c->align);
        self::assertSame(VerticalAlign::TOP, $c->verticalAlign);
    }

    public function testFillWeight(): void
    {
        $c = Column::of('name')->fill(3);
        self::assertSame(3, $c->fillWeight);
        self::assertNull($c->fixedWidth);
    }

    public function testWidthAndFillAreMutuallyExclusive(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Column "name" cannot set both width and fill');
        Column::of('name')->width(20.0)->fill(2);
    }

    public function testNegativeWidthThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Column "x" width must be positive, got -1');
        Column::of('x')->width(-1.0);
    }

    public function testNonPositiveFillWeightThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Column "x" fill weight must be positive, got 0');
        Column::of('x')->fill(0);
    }

    public function testPaddingOverride(): void
    {
        $c = Column::of('x')->padding(CellPadding::all(3.0));
        self::assertEquals(CellPadding::all(3.0), $c->padding);
    }
}
