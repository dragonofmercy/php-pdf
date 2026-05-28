<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\ImageEmbedder;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class ImageEmbedderPatternTest extends TestCase
{
    public function testPatternProducesChildIndirectObjectInOutput(): void
    {
        $doc = new Document(Unit::PT);
        $doc->addPage();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50">'
            . '<defs><pattern id="p" patternUnits="userSpaceOnUse" width="10" height="10"><rect width="5" height="5" fill="#f00"/></pattern></defs>'
            . '<rect width="50" height="50" fill="url(#p)"/>'
            . '</svg>';
        $doc->getCurrentPage()->image(Image::fromBytes($svg), x: 50.0, y: 50.0, w: 200.0);
        $bytes = $doc->output();

        // The PDF must contain a PatternType 1 stream dict (the tile object).
        self::assertStringContainsString('/PatternType 1', $bytes);
        self::assertStringContainsString('/PaintType 1', $bytes);
        self::assertStringContainsString('/TilingType 1', $bytes);
        self::assertStringContainsString('/XStep', $bytes);
        self::assertStringContainsString('/YStep', $bytes);
        // The parent Form XObject's /Resources/Pattern must reference the tiling pattern by /Pn N 0 R.
        self::assertMatchesRegularExpression('#/Pattern\s*<<\s*/P0\s+\d+\s+0\s+R#', $bytes);
    }

    public function testObjectCountIncludesEmbeddedPatterns(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50">'
            . '<defs><pattern id="p" patternUnits="userSpaceOnUse" width="10" height="10"><rect width="5" height="5" fill="#f00"/></pattern></defs>'
            . '<rect width="50" height="50" fill="url(#p)"/>'
            . '</svg>';
        $img = Image::fromBytes($svg);
        // 1 for the SVG Form XObject + 1 for the embedded tiling pattern child = 2.
        self::assertSame(2, ImageEmbedder::objectCount($img));
    }

    public function testObjectCountStaysOneForPatternlessSvg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#00f"/></svg>';
        $img = Image::fromBytes($svg);
        self::assertSame(1, ImageEmbedder::objectCount($img));
    }
}
