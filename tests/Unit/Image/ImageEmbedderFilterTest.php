<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use PHPUnit\Framework\TestCase;

final class ImageEmbedderFilterTest extends TestCase
{
    public function testFilteredSvgProducesImageAndSmaskObjects(): void
    {
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
          <filter id="f"><feGaussianBlur stdDeviation="3"/></filter>
          <rect x="20" y="20" width="60" height="60" fill="red" filter="url(#f)"/>
        </svg>
        SVG;

        $doc = new Document();
        $page = $doc->addPage();
        $page->image(Image::fromBytes($svg), 10, 10, 80, 80);
        $pdf = $doc->output();

        self::assertStringContainsString('/Subtype /Image', $pdf);
        self::assertStringContainsString('/SMask', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $pdf);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $pdf);
    }
}
