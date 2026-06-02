<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\EmbeddedFilter;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererFilterTest extends TestCase
{
    public function testFilteredElementEmitsImageXObjectReference(): void
    {
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
          <filter id="f"><feGaussianBlur stdDeviation="3"/></filter>
          <rect x="20" y="20" width="60" height="60" fill="red" filter="url(#f)"/>
        </svg>
        SVG;
        $meta = Parser::parse($svg);
        $result = (new Renderer())->render($meta);
        self::assertArrayHasKey('embeddedFilters', $result);
        self::assertCount(1, $result['embeddedFilters']);
        $bytes = $result['bytes'];
        self::assertStringContainsString('/ImF0 Do', $bytes);

        // The 60-unit filter region must rasterize at the filter DPI alone
        // (60 * 300/72 = 250 px), NOT collapsed by the ~0.01 prologue ctm scale
        // (which previously yielded a ~3x3 px near-empty raster).
        $filter = $result['embeddedFilters'][0];
        self::assertInstanceOf(EmbeddedFilter::class, $filter);
        self::assertGreaterThanOrEqual(100, $filter->widthPx);
        self::assertGreaterThanOrEqual(100, $filter->heightPx);
    }

    public function testFilteredTextRendersSharpOnTop(): void
    {
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">
          <filter id="f"><feGaussianBlur stdDeviation="3"/></filter>
          <g filter="url(#f)"><rect width="100" height="100" fill="blue"/><text x="10" y="50">Hi</text></g>
        </svg>
        SVG;
        $meta = Parser::parse($svg);
        $result = (new Renderer())->render($meta);
        $bytes = $result['bytes'];
        self::assertStringContainsString('/ImF0 Do', $bytes);
        self::assertStringContainsString('BT', $bytes);
    }
}
