<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererPatternTest extends TestCase
{
    public function testTilingPatternProducesEmbeddedPatternEntry(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<pattern id="p" patternUnits="userSpaceOnUse" x="0" y="0" width="10" height="10">'
            . '<rect width="5" height="5" fill="#f00"/>'
            . '</pattern>'
            . '</defs>'
            . '<rect width="100" height="100" fill="url(#p)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertArrayHasKey('embeddedPatterns', $rendered);
        self::assertCount(1, $rendered['embeddedPatterns']);
        $first = $rendered['embeddedPatterns'][0];
        self::assertSame([0.0, 0.0, 10.0, 10.0], $first->bbox);
        self::assertSame(10.0, $first->xStep);
        self::assertSame(10.0, $first->yStep);
        self::assertCount(6, $first->matrix);
        self::assertNotEmpty($first->contentBytes);
        // The content stream must include the inner rect's painting ops.
        self::assertStringContainsString('re', $first->contentBytes); // rect operator
        self::assertStringContainsString('f', $first->contentBytes);   // fill operator
        // The outer content references the pattern as /P0.
        self::assertStringContainsString('/Pattern cs', $rendered['bytes']);
        self::assertStringContainsString('/P0 scn', $rendered['bytes']);
    }

    public function testTilingPatternResultIncludesPatternRegistry(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><pattern id="p" patternUnits="userSpaceOnUse" width="10" height="10"><rect width="3" height="3"/></pattern></defs>'
            . '<rect width="100" height="100" fill="url(#p)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertArrayHasKey('patternRefs', $rendered);
        self::assertSame([['name' => 'P0', 'embeddedIndex' => 0]], $rendered['patternRefs']);
    }

    public function testZeroWidthPatternFallsBackToBlack(): void
    {
        // Malformed pattern with width=0: paintTilingPattern must NOT emit a
        // PatternType 1 dict (which would carry /XStep 0, illegal in PDF).
        // Fall back to black fill instead.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<pattern id="p" patternUnits="userSpaceOnUse" width="0" height="10">'
            . '<rect width="5" height="5" fill="#f00"/>'
            . '</pattern>'
            . '</defs>'
            . '<rect width="100" height="100" fill="url(#p)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertSame([], $rendered['embeddedPatterns'], 'no embedded pattern should be created for zero-step tile');
        self::assertSame([], $rendered['patternRefs'], 'no pattern ref entry should be created');
        // Outer bytes should contain a solid black fill instead of a pattern reference.
        self::assertStringContainsString('0 0 0 rg', $rendered['bytes']);
        self::assertStringNotContainsString('/Pattern cs', $rendered['bytes']);
    }

    public function testZeroHeightPatternFallsBackToBlack(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<pattern id="p" patternUnits="userSpaceOnUse" width="10" height="0">'
            . '<rect width="5" height="5" fill="#f00"/>'
            . '</pattern>'
            . '</defs>'
            . '<rect width="100" height="100" fill="url(#p)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $rendered = (new Renderer())->render($meta);
        self::assertSame([], $rendered['embeddedPatterns']);
    }
}
