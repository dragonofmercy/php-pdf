<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Align;
use DragonOfMercy\PhpPdf\Svg\MeetOrSlice;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgImage;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use PHPUnit\Framework\TestCase;

final class ImageParseTest extends TestCase
{
    private function pngDataUri(int $w = 4, int $h = 2): string
    {
        return 'data:image/png;base64,' . base64_encode(TestImageFactory::pngRgb($w, $h));
    }

    private function jpegDataUri(int $w = 4, int $h = 2): string
    {
        return 'data:image/jpeg;base64,' . base64_encode(TestImageFactory::stubJpegRgb($w, $h));
    }

    public function testPngDataUriDecodesToImageNode(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image x="10" y="20" width="80" height="40" href="' . $this->pngDataUri(4, 2) . '"/></svg>';
        $meta = Parser::parse($svg);
        self::assertCount(1, $meta->embeddedImages);
        $node = $meta->root->children[0];
        self::assertInstanceOf(SvgImage::class, $node);
        self::assertSame(10.0, $node->x);
        self::assertSame(20.0, $node->y);
        self::assertSame(80.0, $node->width);
        self::assertSame(40.0, $node->height);
        self::assertSame(0, $node->imageIndex);
        self::assertSame(4, $node->intrinsicWidth);
        self::assertSame(2, $node->intrinsicHeight);
    }

    public function testJpegDataUriDecodes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image width="50" height="50" href="' . $this->jpegDataUri() . '"/></svg>';
        $meta = Parser::parse($svg);
        self::assertCount(1, $meta->embeddedImages);
        self::assertInstanceOf(SvgImage::class, $meta->root->children[0]);
    }

    public function testExternalHrefSkipped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image width="50" height="50" href="photo.png"/></svg>';
        $meta = Parser::parse($svg);
        self::assertSame([], $meta->embeddedImages);
        self::assertSame([], $meta->root->children);
    }

    public function testNonRasterDataUriSkipped(): void
    {
        $svgUri = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image width="50" height="50" href="' . $svgUri . '"/></svg>';
        $meta = Parser::parse($svg);
        self::assertSame([], $meta->embeddedImages);
        self::assertSame([], $meta->root->children);
    }

    public function testInvalidBase64Skipped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image width="50" height="50" href="data:image/png;base64,!!!notbase64!!!"/></svg>';
        $meta = Parser::parse($svg);
        self::assertSame([], $meta->root->children);
    }

    public function testZeroWidthSkipped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image width="0" height="40" href="' . $this->pngDataUri() . '"/></svg>';
        $meta = Parser::parse($svg);
        self::assertSame([], $meta->root->children);
    }

    public function testMissingDimensionSkipped(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image width="40" href="' . $this->pngDataUri() . '"/></svg>';
        $meta = Parser::parse($svg);
        self::assertSame([], $meta->root->children);
    }

    public function testDuplicateDataUriDeduped(): void
    {
        $uri = $this->pngDataUri(4, 2);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image x="0" width="40" height="40" href="' . $uri . '"/>'
            . '<image x="50" width="40" height="40" href="' . $uri . '"/></svg>';
        $meta = Parser::parse($svg);
        self::assertCount(1, $meta->embeddedImages);
        $a = $meta->root->children[0];
        $b = $meta->root->children[1];
        self::assertInstanceOf(SvgImage::class, $a);
        self::assertInstanceOf(SvgImage::class, $b);
        self::assertSame(0, $a->imageIndex);
        self::assertSame(0, $b->imageIndex);
    }

    public function testOpacityAndTransformCaptured(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image width="40" height="40" opacity="0.5" transform="translate(5 5)" href="' . $this->pngDataUri() . '"/></svg>';
        $meta = Parser::parse($svg);
        $node = $meta->root->children[0];
        self::assertInstanceOf(SvgImage::class, $node);
        self::assertSame(0.5, $node->opacity);
        self::assertNotNull($node->transform);
    }

    public function testPreserveAspectRatioParsed(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image width="40" height="40" preserveAspectRatio="xMinYMax slice" href="' . $this->pngDataUri() . '"/></svg>';
        $meta = Parser::parse($svg);
        $node = $meta->root->children[0];
        self::assertInstanceOf(SvgImage::class, $node);
        self::assertSame(Align::X_MIN_Y_MAX, $node->aspectRatio->align);
        self::assertSame(MeetOrSlice::SLICE, $node->aspectRatio->meetOrSlice);
    }

    public function testNonImageBase64PayloadSkipped(): void
    {
        // Valid base64, but the decoded bytes are not a PNG/JPEG/SVG -> Image::fromBytes throws -> node skipped.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<image width="40" height="40" href="data:text/plain;base64,' . base64_encode('hello world not an image at all') . '"/></svg>';
        $meta = Parser::parse($svg);
        self::assertSame([], $meta->root->children);
        self::assertSame([], $meta->embeddedImages);
    }
}
