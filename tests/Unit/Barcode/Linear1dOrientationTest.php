<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Code39;
use DragonOfMercy\PhpPdf\Barcode\Code93;
use DragonOfMercy\PhpPdf\Barcode\Itf;
use DragonOfMercy\PhpPdf\Barcode\Orientation;
use DragonOfMercy\PhpPdf\Barcode\OrientableBarcode;
use DragonOfMercy\PhpPdf\Color;
use PHPUnit\Framework\TestCase;

final class Linear1dOrientationTest extends TestCase
{
    public function testCode39Orientable(): void
    {
        $code = Code39::of('ABC');
        self::assertInstanceOf(OrientableBarcode::class, $code);
        self::assertSame(Orientation::Horizontal, $code->orientation());
        self::assertSame(Orientation::Vertical, $code->vertical()->orientation());
    }

    public function testCode39WithCheckDigitPreservesOrientation(): void
    {
        $v = Code39::of('ABC')->vertical()->withCheckDigit();
        self::assertSame(Orientation::Vertical, $v->orientation());
        self::assertTrue($v->hasCheckDigit);
    }

    public function testCode93Orientable(): void
    {
        self::assertSame(Orientation::Vertical, Code93::of('XYZ')->vertical()->orientation());
    }

    public function testItfOrientable(): void
    {
        self::assertSame(Orientation::Vertical, Itf::of('1234')->vertical()->orientation());
    }

    public function testItfWithBearerBarPreservesOrientation(): void
    {
        $v = Itf::of('1234')->vertical()->withBearerBar();
        self::assertSame(Orientation::Vertical, $v->orientation());
        self::assertNotNull($v->bearerBarModules);
    }

    public function testHorizontalIsDefaultForAll(): void
    {
        self::assertSame(Orientation::Horizontal, Code39::of('ABC')->orientation());
        self::assertSame(Orientation::Horizontal, Code93::of('XYZ')->orientation());
        self::assertSame(Orientation::Horizontal, Itf::of('1234')->orientation());
    }

    public function testWithoutTextPreservesOrientationAcrossFormats(): void
    {
        self::assertSame(Orientation::Vertical, Code39::of('ABC')->vertical()->withoutText()->orientation());
        self::assertSame(Orientation::Vertical, Code93::of('XYZ')->vertical()->withoutText()->orientation());
        self::assertSame(Orientation::Vertical, Itf::of('1234')->vertical()->withoutText()->orientation());
    }

    public function testWithColorPreservesOrientationAcrossFormats(): void
    {
        $red = Color::rgb(255, 0, 0);
        self::assertSame(Orientation::Vertical, Code39::of('ABC')->vertical()->withColor($red)->orientation());
        self::assertSame(Orientation::Vertical, Code93::of('XYZ')->vertical()->withColor($red)->orientation());
        self::assertSame(Orientation::Vertical, Itf::of('1234')->vertical()->withColor($red)->orientation());
    }

    public function testVerticalReturnsDistinctInstance(): void
    {
        $h = Code39::of('ABC');
        self::assertNotSame($h, $h->vertical());
    }
}
