<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererMarkerTest extends TestCase
{
    public function testMarkerOnLineEmitsRepeatedRectOperators(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><marker id="m" markerWidth="3" markerHeight="3" refX="0" refY="0"><rect width="3" height="3" fill="#f00"/></marker></defs>'
            . '<line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-width="1" marker-start="url(#m)" marker-end="url(#m)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        $bytes = $rendered['bytes'];
        // The marker rect with width=3, height=3 emits "0 0 3 3 re" twice (once per placement).
        self::assertGreaterThanOrEqual(2, substr_count($bytes, '0 0 3 3 re'));
    }

    public function testNoMarkerNoRectOperator(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-width="1"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertStringNotContainsString('0 0 3 3 re', $rendered['bytes']);
    }

    public function testMarkerStrokeWidthScalesByStrokeWidth(): void
    {
        // markerUnits=strokeWidth (default) -> marker scale = current stroke width.
        // stroke-width=4 -> marker scale factor 4 -> "4 0 0 4" appears in the marker's cm.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><marker id="m" markerWidth="3" markerHeight="3"><rect width="3" height="3"/></marker></defs>'
            . '<line x1="0" y1="0" x2="100" y2="0" stroke="#000" stroke-width="4" marker-end="url(#m)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertStringContainsString('4 0 0 4', $rendered['bytes']);
    }
}
