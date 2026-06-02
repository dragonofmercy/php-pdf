<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\BoxBlur;
use DragonOfMercy\PhpPdf\Svg\Filter\RasterBuffer;
use PHPUnit\Framework\TestCase;

final class BoxBlurTest extends TestCase
{
    public function testZeroStdDeviationIsIdentity(): void
    {
        $buf = new RasterBuffer(3, 3);
        $buf->setPixel(1, 1, 1.0, 1.0, 1.0, 1.0);
        $out = BoxBlur::apply($buf, 0.0, 0.0);
        self::assertSame([1.0, 1.0, 1.0, 1.0], $out->pixel(1, 1));
        self::assertSame([0.0, 0.0, 0.0, 0.0], $out->pixel(0, 0));
    }

    public function testBlurSpreadsAlpha(): void
    {
        $buf = new RasterBuffer(9, 9);
        $buf->setPixel(4, 4, 1.0, 1.0, 1.0, 1.0);
        $out = BoxBlur::apply($buf, 2.0, 2.0);
        $centerA = $out->pixel(4, 4)[3];
        $neighborA = $out->pixel(3, 4)[3];
        self::assertLessThan(1.0, $centerA);
        self::assertGreaterThan(0.0, $neighborA);
    }

    public function testBoxSizeFormula(): void
    {
        // 3*sqrt(2*pi)/4 ~= 1.8800; floor(2.0*1.8800 + 0.5) = floor(4.26) = 4
        self::assertSame(4, BoxBlur::boxSizeFor(2.0));
        self::assertSame(0, BoxBlur::boxSizeFor(0.0));
    }
}
