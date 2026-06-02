<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Filter;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Svg\Align;
use DragonOfMercy\PhpPdf\Svg\Filter\SubtreeRasterizer;
use DragonOfMercy\PhpPdf\Svg\MeetOrSlice;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgImage;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use DragonOfMercy\PhpPdf\Svg\SvgText;
use PHPUnit\Framework\TestCase;

final class SubtreeRasterizerTest extends TestCase
{
    public function testFillsSolidRectIntoBuffer(): void
    {
        $paint = SvgPaint::default()->withFill(new SvgColor(1.0, 0.0, 0.0));
        $rect = new SvgRect(null, $paint, 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $group = new SvgGroup(null, [$rect]);

        $buf = (new SubtreeRasterizer())->rasterize($group, SvgMatrix::identity(), 10, 10);

        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0], $buf->pixel(5, 5), 1e-6);
    }

    public function testTextIsSkipped(): void
    {
        $text = new SvgText(null, []);
        $group = new SvgGroup(null, [$text]);

        $buf = (new SubtreeRasterizer())->rasterize($group, SvgMatrix::identity(), 10, 10);

        self::assertSame([0.0, 0.0, 0.0, 0.0], $buf->pixel(5, 5));
    }

    public function testBlitsPngImage(): void
    {
        // 2x2 solid-red RGB PNG decoded through PngMetadata.
        $image = Image::fromBytes(self::redPng2x2());
        $aspect = new PreserveAspectRatio(Align::NONE, MeetOrSlice::MEET);
        $svgImage = new SvgImage(
            transform: null,
            x: 0.0,
            y: 0.0,
            width: 4.0,
            height: 4.0,
            aspectRatio: $aspect,
            opacity: 1.0,
            imageIndex: 0,
            intrinsicWidth: 2,
            intrinsicHeight: 2,
        );
        $group = new SvgGroup(null, [$svgImage]);

        $buf = (new SubtreeRasterizer([$image]))->rasterize($group, SvgMatrix::identity(), 4, 4);

        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0], $buf->pixel(2, 2), 1e-6);
        self::assertEqualsWithDelta([1.0, 0.0, 0.0, 1.0], $buf->pixel(0, 0), 1e-6);
    }

    /**
     * Builds a minimal 2x2 8-bit RGB PNG with every pixel red. CRCs are zeroed
     * (PngMetadata does not validate them).
     */
    private static function redPng2x2(): string
    {
        $signature = "\x89PNG\r\n\x1A\n";

        // IHDR: width=2, height=2, bitDepth=8, colorType=2 (RGB), rest 0.
        $ihdr = pack('NN', 2, 2) . "\x08\x02\x00\x00\x00";

        // Two scanlines, each: filter byte 0 then 2 RGB pixels (255,0,0).
        $red = "\xFF\x00\x00";
        $scanline = "\x00" . $red . $red;
        $rawData = $scanline . $scanline;
        $compressed = gzcompress($rawData, 6);
        self::assertIsString($compressed);

        return $signature
            . self::pngChunk('IHDR', $ihdr)
            . self::pngChunk('IDAT', $compressed)
            . self::pngChunk('IEND', '');
    }

    private static function pngChunk(string $type, string $payload): string
    {
        return pack('N', strlen($payload)) . $type . $payload . "\x00\x00\x00\x00";
    }
}
