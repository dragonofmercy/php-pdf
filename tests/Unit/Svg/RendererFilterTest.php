<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

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
