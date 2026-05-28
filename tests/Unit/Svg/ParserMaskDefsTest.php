<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class ParserMaskDefsTest extends TestCase
{
    public function testMaskElementIsDefsOnly(): void
    {
        // <mask> appearing at the root (not under <defs>) must still be defs-only:
        // not rendered as a sibling of the rect.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<mask id="m"><rect x="0" y="0" width="100" height="100" fill="white"/></mask>'
            . '<rect x="0" y="0" width="100" height="100" fill="red" mask="url(#m)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        self::assertCount(1, $meta->root->children);
        self::assertInstanceOf(SvgMasked::class, $meta->root->children[0]);
    }

    public function testNestedMaskRefInsideMaskIsIgnored(): void
    {
        // A <mask> definition referencing another mask must NOT recursively mask
        // its own contents (inMask flag silences mask resolution inside mask).
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<mask id="inner"><rect x="0" y="0" width="100" height="100" fill="white"/></mask>'
            . '<mask id="outer">'
            .   '<rect x="0" y="0" width="100" height="100" fill="white" mask="url(#inner)"/>'
            . '</mask>'
            . '</defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="red" mask="url(#outer)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $node = $meta->root->children[0];
        self::assertInstanceOf(SvgMasked::class, $node);
        // The outer mask's single child is a plain rect (no nested SvgMasked).
        self::assertCount(1, $node->mask->nodes);
        self::assertInstanceOf(SvgRect::class, $node->mask->nodes[0]);
    }

    public function testMalformedMaskUrlIsSilent(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<rect x="0" y="0" width="100" height="100" fill="red" mask="garbage"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $node = $meta->root->children[0];
        self::assertNotInstanceOf(SvgMasked::class, $node);
        self::assertInstanceOf(SvgRect::class, $node);
    }
}
