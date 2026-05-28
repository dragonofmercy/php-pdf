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

    public function testMarkerAngleNegatedForYFlip(): void
    {
        // Vertical-down line: MarkerPositioner returns angle=90 (math CCW from +X).
        // After fix, the rendered marker rotation is -90 (visual CW = pointing down).
        // The cm matrix for rotate(-90) is: cos(-90)=0, sin(-90)=-1 -> [0 -1 1 0 tx ty cm].
        // We check for the "-1" and "0 -1" signature which only appears under rotate(-90).
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><marker id="m" markerWidth="3" markerHeight="3" orient="auto"><rect width="3" height="3"/></marker></defs>'
            . '<line x1="50" y1="10" x2="50" y2="90" stroke="#000" stroke-width="1" marker-end="url(#m)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new \DragonOfMercy\PhpPdf\Svg\Renderer())->render($meta);
        // For a vertical-down line, atan2(80, 0) = +90 deg. After the negation fix:
        // rotate(-90) produces matrix [cos(-90) sin(-90) -sin(-90) cos(-90)] = [0 -1 1 0].
        // The cm line in the marker block should contain "0 -1 1 0" pattern.
        self::assertMatchesRegularExpression('/0 -1 1 0 [\d.\-]+ [\d.\-]+ cm/', $rendered['bytes']);
    }
}
