<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\ColorMatrix;
use DragonOfMercy\PhpPdf\Svg\Filter\ColorMatrixType;
use DragonOfMercy\PhpPdf\Svg\Filter\RasterBuffer;
use PHPUnit\Framework\TestCase;

final class ColorMatrixTest extends TestCase
{
    public function testIdentityMatrixLeavesPixelUnchanged(): void
    {
        $buf = new RasterBuffer(1, 1);
        $buf->setPixel(0, 0, 0.2, 0.4, 0.6, 0.8);
        $out = ColorMatrix::apply($buf, ColorMatrixType::MATRIX, [
            1,0,0,0,0, 0,1,0,0,0, 0,0,1,0,0, 0,0,0,1,0,
        ]);
        $p = $out->pixel(0, 0);
        self::assertEqualsWithDelta(0.2, $p[0], 1e-9);
        self::assertEqualsWithDelta(0.8, $p[3], 1e-9);
    }

    public function testSaturateZeroIsGrayscale(): void
    {
        $buf = new RasterBuffer(1, 1);
        $buf->setPixel(0, 0, 1.0, 0.0, 0.0, 1.0);
        $out = ColorMatrix::apply($buf, ColorMatrixType::SATURATE, [0.0]);
        $p = $out->pixel(0, 0);
        self::assertEqualsWithDelta(0.2126, $p[0], 1e-3);
        self::assertEqualsWithDelta(0.2126, $p[1], 1e-3);
        self::assertEqualsWithDelta(0.2126, $p[2], 1e-3);
    }

    public function testLuminanceToAlpha(): void
    {
        $buf = new RasterBuffer(1, 1);
        $buf->setPixel(0, 0, 1.0, 1.0, 1.0, 1.0);
        $out = ColorMatrix::apply($buf, ColorMatrixType::LUMINANCE_TO_ALPHA, []);
        $p = $out->pixel(0, 0);
        self::assertEqualsWithDelta(0.0, $p[0], 1e-9);
        self::assertEqualsWithDelta(1.0, $p[3], 1e-4);
    }
}
