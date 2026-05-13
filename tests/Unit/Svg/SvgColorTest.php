<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use PHPUnit\Framework\TestCase;

final class SvgColorTest extends TestCase
{
    public function testConstructorStoresUnitFloats(): void
    {
        $c = new SvgColor(0.25, 0.5, 0.75);
        self::assertSame(0.25, $c->r);
        self::assertSame(0.5, $c->g);
        self::assertSame(0.75, $c->b);
    }

    public function testFromBytesClampsAndDivides(): void
    {
        $c = SvgColor::fromBytes(0, 128, 255);
        self::assertSame(0.0, $c->r);
        self::assertEqualsWithDelta(128.0 / 255.0, $c->g, 1e-9);
        self::assertSame(1.0, $c->b);
    }

    public function testFromBytesClampsAbove255(): void
    {
        $c = SvgColor::fromBytes(300, -10, 128);
        self::assertSame(1.0, $c->r);
        self::assertSame(0.0, $c->g);
    }

    public function testBlackHelper(): void
    {
        $c = SvgColor::black();
        self::assertSame(0.0, $c->r);
        self::assertSame(0.0, $c->g);
        self::assertSame(0.0, $c->b);
    }

    public function testConstructorRejectsBelowZero(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('SvgColor component must be in [0, 1], got -0.1');
        new SvgColor(-0.1, 0.0, 0.0);
    }

    public function testConstructorRejectsAboveOne(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('SvgColor component must be in [0, 1], got 1.5');
        new SvgColor(0.0, 1.5, 0.0);
    }
}
