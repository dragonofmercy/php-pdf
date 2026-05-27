<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\ClipPathUnits;
use DragonOfMercy\PhpPdf\Svg\FillRule;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use PHPUnit\Framework\TestCase;

final class ParserClipTest extends TestCase
{
    /** @return list<SvgNode> */
    private function topChildren(string $svg): array
    {
        return Parser::parse($svg)->root->children;
    }

    public function testClipPathReferenceWrapsElement(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<clipPath id="c"><circle cx="50" cy="50" r="40"/></clipPath>'
            . '<rect x="0" y="0" width="100" height="100" clip-path="url(#c)"/>'
            . '</svg>';
        $children = $this->topChildren($svg);
        self::assertCount(1, $children);
        self::assertInstanceOf(SvgClipped::class, $children[0]);
        self::assertSame(ClipPathUnits::USER_SPACE_ON_USE, $children[0]->clip->units);
        self::assertCount(1, $children[0]->clip->nodes);
        self::assertSame(FillRule::NONZERO, $children[0]->clip->clipRule);
    }

    public function testObjectBoundingBoxUnitsParsed(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<clipPath id="c" clipPathUnits="objectBoundingBox"><rect x="0" y="0" width="0.5" height="1"/></clipPath>'
            . '<rect x="0" y="0" width="100" height="100" clip-path="url(#c)"/>'
            . '</svg>';
        $children = $this->topChildren($svg);
        self::assertInstanceOf(SvgClipped::class, $children[0]);
        self::assertSame(ClipPathUnits::OBJECT_BOUNDING_BOX, $children[0]->clip->units);
    }

    public function testEvenoddClipRule(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<clipPath id="c"><path d="M0 0 H10 V10 Z" clip-rule="evenodd"/></clipPath>'
            . '<rect x="0" y="0" width="100" height="100" clip-path="url(#c)"/>'
            . '</svg>';
        $children = $this->topChildren($svg);
        self::assertInstanceOf(SvgClipped::class, $children[0]);
        self::assertSame(FillRule::EVENODD, $children[0]->clip->clipRule);
    }

    public function testMissingClipReferenceRendersPlain(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<rect x="0" y="0" width="100" height="100" clip-path="url(#nope)"/>'
            . '</svg>';
        $children = $this->topChildren($svg);
        self::assertCount(1, $children);
        self::assertNotInstanceOf(SvgClipped::class, $children[0]);
    }

    public function testClipPathNone(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<rect x="0" y="0" width="100" height="100" clip-path="none"/>'
            . '</svg>';
        $children = $this->topChildren($svg);
        self::assertNotInstanceOf(SvgClipped::class, $children[0]);
    }

    public function testClipPathViaCssClass(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<style>.clipped { clip-path: url(#c) }</style>'
            . '<clipPath id="c"><circle cx="50" cy="50" r="40"/></clipPath>'
            . '<rect class="clipped" x="0" y="0" width="100" height="100"/>'
            . '</svg>';
        $children = $this->topChildren($svg);
        self::assertInstanceOf(SvgClipped::class, $children[0]);
    }

    public function testClipChildOwnClipPathIsIgnored(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<clipPath id="inner"><rect x="0" y="0" width="10" height="10"/></clipPath>'
            . '<clipPath id="c"><circle cx="50" cy="50" r="40" clip-path="url(#inner)"/></clipPath>'
            . '<rect x="0" y="0" width="100" height="100" clip-path="url(#c)"/>'
            . '</svg>';
        $children = $this->topChildren($svg);
        self::assertInstanceOf(SvgClipped::class, $children[0]);
        self::assertCount(1, $children[0]->clip->nodes);
        self::assertNotInstanceOf(SvgClipped::class, $children[0]->clip->nodes[0]);
    }
}
