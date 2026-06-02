<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\Filter\Composite;
use DragonOfMercy\PhpPdf\Svg\Filter\CompositeOperator;
use DragonOfMercy\PhpPdf\Svg\Filter\RasterBuffer;
use PHPUnit\Framework\TestCase;

final class CompositeTest extends TestCase
{
    private function solid(float $r, float $g, float $b, float $a): RasterBuffer
    {
        $buf = new RasterBuffer(1, 1);
        $buf->setPixel(0, 0, $r, $g, $b, $a);
        return $buf;
    }

    public function testOverOpaqueSourceHidesDest(): void
    {
        $src = $this->solid(1.0, 0.0, 0.0, 1.0);
        $dst = $this->solid(0.0, 0.0, 1.0, 1.0);
        $out = Composite::apply($src, $dst, CompositeOperator::OVER, 0, 0, 0, 0);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0], $out->pixel(0, 0), 1e-9);
    }

    public function testInKeepsSourceWhereDestPresent(): void
    {
        $src = $this->solid(1.0, 0.0, 0.0, 1.0);
        $dstTransparent = $this->solid(0.0, 0.0, 0.0, 0.0);
        $out = Composite::apply($src, $dstTransparent, CompositeOperator::IN, 0, 0, 0, 0);
        self::assertEqualsWithDelta(0.0, $out->pixel(0, 0)[3], 1e-9);
    }

    public function testArithmeticSum(): void
    {
        $a = $this->solid(0.5, 0.0, 0.0, 1.0);
        $b = $this->solid(0.25, 0.0, 0.0, 1.0);
        $out = Composite::apply($a, $b, CompositeOperator::ARITHMETIC, 0.0, 1.0, 1.0, 0.0);
        self::assertEqualsWithDelta(0.75, $out->pixel(0, 0)[0], 1e-6);
    }

    public function testMismatchedSizesThrow(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        Composite::apply(new RasterBuffer(2, 2), new RasterBuffer(3, 3), CompositeOperator::OVER, 0, 0, 0, 0);
    }
}
