<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image;
use PHPUnit\Framework\TestCase;

final class DocumentSvgFilterResolutionTest extends TestCase
{
    public function testDefaultAndCustomResolutionFluent(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->setSvgFilterResolution(150));
    }

    public function testRejectsNonPositiveResolution(): void
    {
        $this->expectException(PdfException::class);
        (new Document())->setSvgFilterResolution(0);
    }

    public function testCustomResolutionAffectsOutput(): void
    {
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
          <filter id="f"><feGaussianBlur stdDeviation="3"/></filter>
          <rect x="20" y="20" width="60" height="60" fill="red" filter="url(#f)"/>
        </svg>
        SVG;
        $low = new Document();
        $low->setSvgFilterResolution(50);
        $low->addPage()->image(Image::fromBytes($svg), 10, 10, 80, 80);
        $lowPdf = $low->output();

        $high = new Document();
        $high->setSvgFilterResolution(300);
        $high->addPage()->image(Image::fromBytes($svg), 10, 10, 80, 80);
        $highPdf = $high->output();

        // Higher DPI -> larger raster -> larger /Width in the image XObject -> different (larger) PDF.
        self::assertNotSame(strlen($lowPdf), strlen($highPdf));
    }
}
