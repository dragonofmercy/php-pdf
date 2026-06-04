<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;
use DragonOfMercy\PhpPdf\Table\Cell;
use PHPUnit\Framework\TestCase;

final class CellTest extends TestCase
{
    public function testTextCellDefaults(): void
    {
        $c = Cell::of('Alice');
        self::assertTrue($c->isText());
        self::assertFalse($c->isImage());
        self::assertSame('Alice', $c->text);
        self::assertNull($c->align);
        self::assertNull($c->bold);
    }

    public function testTextCellStyleOverrides(): void
    {
        $c = Cell::of('Total')->bold()->align(TextAlign::CENTER)->textColor(Color::rgb(192, 0, 0));
        self::assertTrue($c->bold);
        self::assertSame(TextAlign::CENTER, $c->align);
        self::assertEquals(Color::rgb(192, 0, 0), $c->textColor);
    }

    public function testStringableIsCoerced(): void
    {
        $c = Cell::of(new class implements \Stringable {
            public function __toString(): string { return '42'; }
        });
        self::assertSame('42', $c->text);
    }

    public function testImageCellDefaults(): void
    {
        $img = Image::fromFile(__DIR__ . '/../../Golden/assets/png-alpha-rgba-16x16.png');
        $c = Cell::image($img, w: 10.0, h: 10.0);
        self::assertTrue($c->isImage());
        self::assertFalse($c->isText());
        self::assertSame($img, $c->image);
        self::assertSame(10.0, $c->imageWidth);
        self::assertSame(10.0, $c->imageHeight);
        // image cells default to centered / middle
        self::assertSame(TextAlign::CENTER, $c->align);
        self::assertSame(VerticalAlign::MIDDLE, $c->verticalAlign);
    }

    public function testImageCellAcceptsPath(): void
    {
        $c = Cell::image(__DIR__ . '/../../Golden/assets/png-alpha-rgba-16x16.png', w: 8.0);
        self::assertTrue($c->isImage());
        self::assertInstanceOf(Image::class, $c->image);
        self::assertSame(8.0, $c->imageWidth);
        self::assertNull($c->imageHeight);
    }

    public function testColSpanDefaultsToOne(): void
    {
        self::assertSame(1, Cell::of('x')->colSpan);
    }

    public function testColSpanSetsValueImmutably(): void
    {
        $base = Cell::of('x');
        $spanned = $base->colSpan(3);
        self::assertSame(3, $spanned->colSpan);
        self::assertSame(1, $base->colSpan, 'original Cell must be unchanged');
    }

    public function testColSpanPreservesOtherFields(): void
    {
        $cell = Cell::of('x')->bold()->colSpan(2);
        self::assertSame(2, $cell->colSpan);
        self::assertTrue($cell->bold);
    }

    public function testColSpanBelowOneThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Cell colSpan must be >= 1, got 0');
        Cell::of('x')->colSpan(0);
    }
}
