<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Svg\ViewBox;
use PHPUnit\Framework\TestCase;

final class ViewBoxTest extends TestCase
{
    public function testConstructorStoresFourFloats(): void
    {
        $vb = new ViewBox(0.0, 0.0, 100.0, 50.0);
        self::assertSame(0.0, $vb->x);
        self::assertSame(0.0, $vb->y);
        self::assertSame(100.0, $vb->width);
        self::assertSame(50.0, $vb->height);
    }

    public function testParseFourSpaceSeparatedNumbers(): void
    {
        $vb = ViewBox::parse('0 0 24 24');
        self::assertSame(0.0, $vb->x);
        self::assertSame(0.0, $vb->y);
        self::assertSame(24.0, $vb->width);
        self::assertSame(24.0, $vb->height);
    }

    public function testParseAcceptsCommaSeparatedNumbers(): void
    {
        $vb = ViewBox::parse('0, 0, 100, 50');
        self::assertSame(0.0, $vb->x);
        self::assertSame(100.0, $vb->width);
    }

    public function testParseAcceptsMixedSeparators(): void
    {
        $vb = ViewBox::parse('10, 20 30, 40');
        self::assertSame(10.0, $vb->x);
        self::assertSame(20.0, $vb->y);
        self::assertSame(30.0, $vb->width);
        self::assertSame(40.0, $vb->height);
    }

    public function testParseAcceptsNegativeOrigin(): void
    {
        $vb = ViewBox::parse('-5 -10 20 30');
        self::assertSame(-5.0, $vb->x);
        self::assertSame(-10.0, $vb->y);
    }

    public function testConstructorThrowsOnZeroWidth(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('ViewBox width must be positive, got 0');
        new ViewBox(0.0, 0.0, 0.0, 10.0);
    }

    public function testConstructorThrowsOnNegativeHeight(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('ViewBox height must be positive, got -1');
        new ViewBox(0.0, 0.0, 10.0, -1.0);
    }

    public function testParseRejectsThreeNumbers(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Invalid viewBox: '1 2 3', expected four numbers");
        ViewBox::parse('1 2 3');
    }

    public function testParseRejectsNonNumeric(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Invalid viewBox: 'a b c d'");
        ViewBox::parse('a b c d');
    }
}
