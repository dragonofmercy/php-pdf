<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Orientation;
use DragonOfMercy\PhpPdf\PageFormat;
use DragonOfMercy\PhpPdf\PageSizeResolver;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PageSizeResolverTest extends TestCase
{
    public function testCustomSizeWinsAndConvertsFromUnit(): void
    {
        [$w, $h] = PageSizeResolver::toPoints([100.0, 200.0], PageFormat::A4, Orientation::PORTRAIT, Unit::MM);
        self::assertEqualsWithDelta(Unit::MM->toPoints(100.0), $w, 1e-9);
        self::assertEqualsWithDelta(Unit::MM->toPoints(200.0), $h, 1e-9);
    }

    public function testFormatPortrait(): void
    {
        [$w, $h] = PageSizeResolver::toPoints(null, PageFormat::A4, Orientation::PORTRAIT, Unit::MM);
        [$mmW, $mmH] = PageFormat::A4->dimensionsMm();
        self::assertEqualsWithDelta(Unit::MM->toPoints($mmW), $w, 1e-9);
        self::assertEqualsWithDelta(Unit::MM->toPoints($mmH), $h, 1e-9);
    }

    public function testFormatLandscapeSwaps(): void
    {
        [$wL, $hL] = PageSizeResolver::toPoints(null, PageFormat::A4, Orientation::LANDSCAPE, Unit::MM);
        [$wP, $hP] = PageSizeResolver::toPoints(null, PageFormat::A4, Orientation::PORTRAIT, Unit::MM);
        self::assertEqualsWithDelta($hP, $wL, 1e-9);
        self::assertEqualsWithDelta($wP, $hL, 1e-9);
    }

    public function testValidateCustomAcceptsNumericPair(): void
    {
        self::assertSame([12.0, 34.0], PageSizeResolver::validateCustom([12, 34]));
    }

    public function testValidateCustomRejectsWrongArity(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Custom page format must be [width, height]');
        PageSizeResolver::validateCustom([1.0]);
    }

    public function testValidateCustomRejectsNonNumeric(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Custom page format dimensions must be numeric');
        PageSizeResolver::validateCustom(['a', 'b']);
    }

    public function testValidateCustomRejectsNonPositiveWidth(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page width must be positive, got 0');
        PageSizeResolver::validateCustom([0, 10]);
    }

    public function testValidateCustomRejectsNonPositiveHeight(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page height must be positive, got -5');
        PageSizeResolver::validateCustom([10, -5]);
    }
}
