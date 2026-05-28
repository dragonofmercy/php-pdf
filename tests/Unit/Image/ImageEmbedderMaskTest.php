<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class ImageEmbedderMaskTest extends TestCase
{
    private const string MASKED_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
        . '<defs>'
        . '<mask id="m"><rect x="0" y="0" width="100" height="100" fill="white"/></mask>'
        . '</defs>'
        . '<rect x="0" y="0" width="100" height="100" fill="red" mask="url(#m)"/>'
        . '</svg>';

    public function testSvgWithMaskAllocatesExtraIndirectObject(): void
    {
        $img = Image::fromBytes(self::MASKED_SVG);
        // 1 parent Form + 1 mask Form.
        self::assertSame(2, ImageEmbedder::objectCount($img));
    }

    public function testObjectCountStaysOneForMaskFreeSvg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#00f"/></svg>';
        $img = Image::fromBytes($svg);
        self::assertSame(1, ImageEmbedder::objectCount($img));
    }

    public function testPdfBytesEmitSmaskWithLuminosityAndGroupTransparency(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $doc->getCurrentPage()->image(Image::fromBytes(self::MASKED_SVG), x: 50.0, y: 50.0, w: 100.0);
        $pdf = $doc->output();

        // /SMask, /Luminosity, /Group, /Transparency, /Mask all appear as raw
        // dictionary keys in object headers, not inside compressed stream bodies.
        self::assertStringContainsString('/SMask', $pdf);
        self::assertStringContainsString('/Luminosity', $pdf);
        self::assertStringContainsString('/Group', $pdf);
        self::assertStringContainsString('/Transparency', $pdf);
        self::assertStringContainsString('/Mask', $pdf);
        // The mask Form must declare DeviceRGB as its blending colorspace.
        self::assertStringContainsString('/DeviceRGB', $pdf);
    }

    public function testMaskFormCarriesPatternResourceWhenContentUsesShading(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<linearGradient id="g" x1="0" y1="0" x2="1" y2="0">'
            .   '<stop offset="0" stop-color="red" stop-opacity="1"/>'
            .   '<stop offset="1" stop-color="red" stop-opacity="0"/>'
            . '</linearGradient>'
            . '</defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="url(#g)"/>'
            . '</svg>';
        $doc = new \DragonOfMercy\PhpPdf\Document(\DragonOfMercy\PhpPdf\Unit::PT);
        $doc->addPage();
        $doc->getCurrentPage()->image(\DragonOfMercy\PhpPdf\Image::fromBytes($svg), x: 50.0, y: 50.0, w: 100.0);
        $pdf = $doc->output();
        self::assertStringContainsString('/SMask', $pdf);
        self::assertStringContainsString('/Luminosity', $pdf);
        // /Pattern occurs in the parent and in the mask Form's resources.
        $patternHits = substr_count($pdf, '/Pattern ');
        self::assertGreaterThanOrEqual(2, $patternHits, 'parent + mask form should each declare /Pattern resource');
    }
}
