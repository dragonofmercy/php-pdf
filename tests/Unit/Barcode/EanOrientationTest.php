<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Ean13;
use DragonOfMercy\PhpPdf\Barcode\Ean8;
use DragonOfMercy\PhpPdf\Barcode\Orientation;
use DragonOfMercy\PhpPdf\Barcode\OrientableBarcode;
use DragonOfMercy\PhpPdf\Barcode\Upca;
use DragonOfMercy\PhpPdf\Color;
use PHPUnit\Framework\TestCase;

final class EanOrientationTest extends TestCase
{
    public function testEan13Orientable(): void
    {
        $code = Ean13::of('978013110362');
        self::assertInstanceOf(OrientableBarcode::class, $code);
        self::assertSame(Orientation::Horizontal, $code->orientation());

        $v = $code->vertical();
        self::assertNotSame($code, $v);
        self::assertSame(Orientation::Vertical, $v->orientation());
        // 12-digit input gains its checksum; orientation copy must preserve it.
        self::assertSame('9780131103627', $v->digits);
    }

    public function testEan13WithoutTextPreservesOrientation(): void
    {
        $v = Ean13::of('978013110362')->vertical()->withoutText();
        self::assertSame(Orientation::Vertical, $v->orientation());
        self::assertFalse($v->showText);
    }

    public function testEan13WithColorPreservesOrientation(): void
    {
        $v = Ean13::of('978013110362')->vertical()->withColor(Color::rgb(255, 0, 0));
        self::assertSame(Orientation::Vertical, $v->orientation());
    }

    public function testEan8Orientable(): void
    {
        self::assertSame(Orientation::Horizontal, Ean8::of('1234567')->orientation());
        self::assertSame(Orientation::Vertical, Ean8::of('1234567')->vertical()->orientation());
    }

    public function testUpcaOrientable(): void
    {
        self::assertSame(Orientation::Horizontal, Upca::of('03600029145')->orientation());
        self::assertSame(Orientation::Vertical, Upca::of('03600029145')->vertical()->orientation());
    }

    public function testWithoutTextPreservesOrientationAcrossFormats(): void
    {
        self::assertSame(Orientation::Vertical, Ean13::of('978013110362')->vertical()->withoutText()->orientation());
        self::assertSame(Orientation::Vertical, Ean8::of('1234567')->vertical()->withoutText()->orientation());
        self::assertSame(Orientation::Vertical, Upca::of('03600029145')->vertical()->withoutText()->orientation());
    }

    public function testWithColorPreservesOrientationAcrossFormats(): void
    {
        $red = Color::rgb(255, 0, 0);
        self::assertSame(Orientation::Vertical, Ean13::of('978013110362')->vertical()->withColor($red)->orientation());
        self::assertSame(Orientation::Vertical, Ean8::of('1234567')->vertical()->withColor($red)->orientation());
        self::assertSame(Orientation::Vertical, Upca::of('03600029145')->vertical()->withColor($red)->orientation());
    }

    public function testWithOrientationSetsOrientationDirectly(): void
    {
        $v = Ean13::of('978013110362')->withOrientation(Orientation::Vertical);
        self::assertSame(Orientation::Vertical, $v->orientation());
        self::assertSame('9780131103627', $v->digits);
    }

    public function testHorizontalResetsOrientation(): void
    {
        self::assertSame(Orientation::Horizontal, Ean8::of('1234567')->vertical()->horizontal()->orientation());
    }
}
