<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class ColorTest extends TestCase
{
    public function testRgbFactoryClamps0to255ToPdfFloats(): void
    {
        self::assertSame("1 0 0 rg\n", Color::rgb(255, 0, 0)->toPdfOperator(stroke: false));
    }

    public function testRgbStrokeVariantIsUppercaseOperator(): void
    {
        self::assertSame("1 0 0 RG\n", Color::rgb(255, 0, 0)->toPdfOperator(stroke: true));
    }

    public function testGrayFactory(): void
    {
        // 128 / 255 = 0.5019607843... → truncated to 6 decimals → 0.501961
        self::assertSame("0.501961 g\n", Color::gray(128)->toPdfOperator(stroke: false));
        self::assertSame("0.501961 G\n", Color::gray(128)->toPdfOperator(stroke: true));
    }

    public function testHexSixCharsWithHash(): void
    {
        self::assertSame("1 0 0 rg\n", Color::hex('#ff0000')->toPdfOperator(stroke: false));
    }

    public function testHexSixCharsWithoutHash(): void
    {
        self::assertSame("0 1 0 rg\n", Color::hex('00ff00')->toPdfOperator(stroke: false));
    }

    public function testHexThreeCharsWithHash(): void
    {
        self::assertSame("1 1 1 rg\n", Color::hex('#fff')->toPdfOperator(stroke: false));
    }

    public function testHexThreeCharsWithoutHash(): void
    {
        self::assertSame("0 0 0 rg\n", Color::hex('000')->toPdfOperator(stroke: false));
    }

    public function testHexIsCaseInsensitive(): void
    {
        // 0xAA/255 ≈ 0.666667, 0x88/255 ≈ 0.533333, 0x44/255 ≈ 0.266667
        self::assertSame("0.666667 0.533333 0.266667 rg\n", Color::hex('#AA8844')->toPdfOperator(stroke: false));
    }

    public function testRgbRejectsOutOfRange(): void
    {
        $this->expectException(PdfException::class);
        Color::rgb(256, 0, 0);
    }

    public function testHexRejectsMalformed(): void
    {
        $this->expectException(PdfException::class);
        Color::hex('#xyz');
    }
}
