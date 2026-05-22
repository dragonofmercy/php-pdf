<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\Code128;
use DragonOfMercy\PhpPdf\Barcode\Orientation;
use DragonOfMercy\PhpPdf\Barcode\OrientableBarcode;
use DragonOfMercy\PhpPdf\Color;
use PHPUnit\Framework\TestCase;

final class Code128OrientationTest extends TestCase
{
    public function testDefaultOrientationIsHorizontal(): void
    {
        self::assertSame(Orientation::Horizontal, Code128::of('ABC123')->orientation());
    }

    public function testIsOrientableBarcode(): void
    {
        self::assertInstanceOf(OrientableBarcode::class, Code128::of('ABC123'));
    }

    public function testVerticalReturnsNewInstanceWithVerticalOrientation(): void
    {
        $h = Code128::of('ABC123');
        $v = $h->vertical();

        self::assertNotSame($h, $v);
        self::assertSame(Orientation::Horizontal, $h->orientation());
        self::assertSame(Orientation::Vertical, $v->orientation());
        self::assertSame('ABC123', $v->data);
    }

    public function testWithColorPreservesOrientation(): void
    {
        $red = Color::rgb(255, 0, 0);
        $v = Code128::of('ABC123')->vertical()->withColor($red);
        self::assertSame(Orientation::Vertical, $v->orientation());
        self::assertEquals($red, $v->color);
    }

    public function testWithoutTextPreservesOrientation(): void
    {
        $v = Code128::of('ABC123')->vertical()->withoutText();
        self::assertSame(Orientation::Vertical, $v->orientation());
        self::assertFalse($v->showText);
    }

    public function testWithOrientationSetsOrientationDirectly(): void
    {
        $v = Code128::of('ABC123')->withOrientation(Orientation::Vertical);
        self::assertSame(Orientation::Vertical, $v->orientation());
        self::assertSame('ABC123', $v->data);
    }

    public function testHorizontalResetsOrientation(): void
    {
        $h = Code128::of('ABC123')->vertical()->horizontal();
        self::assertSame(Orientation::Horizontal, $h->orientation());
    }
}
