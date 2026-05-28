<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Mask;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\Mask\MaskUnits;
use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class MaskResolverTest extends TestCase
{
    public function testParserCollectsMaskAndResolvesViaPaintWrap(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<mask id="m1" maskUnits="userSpaceOnUse" maskContentUnits="objectBoundingBox" x="5" y="5" width="50" height="50">'
            . '<rect x="0" y="0" width="1" height="1" fill="white"/>'
            . '</mask>'
            . '</defs>'
            . '<rect x="10" y="10" width="80" height="80" fill="red" mask="url(#m1)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        self::assertInstanceOf(SvgMetadata::class, $meta);
        self::assertCount(1, $meta->root->children);
        $node = $meta->root->children[0];
        self::assertInstanceOf(SvgMasked::class, $node);
        self::assertSame('m1', $node->mask->id);
        self::assertSame(MaskUnits::USER_SPACE_ON_USE, $node->mask->units);
        self::assertSame(MaskUnits::OBJECT_BOUNDING_BOX, $node->mask->contentUnits);
        self::assertSame(5.0, $node->mask->x);
        self::assertSame(5.0, $node->mask->y);
        self::assertSame(50.0, $node->mask->width);
        self::assertSame(50.0, $node->mask->height);
        self::assertCount(1, $node->mask->nodes);
        self::assertInstanceOf(SvgRect::class, $node->mask->nodes[0]);
        self::assertInstanceOf(SvgRect::class, $node->child);
    }

    public function testMaskDefaultsToSpecRegion(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs>'
            . '<mask id="m"><rect x="0" y="0" width="100" height="100" fill="white"/></mask>'
            . '</defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="red" mask="url(#m)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $node = $meta->root->children[0];
        self::assertInstanceOf(SvgMasked::class, $node);
        self::assertSame(MaskUnits::OBJECT_BOUNDING_BOX, $node->mask->units);
        self::assertSame(MaskUnits::USER_SPACE_ON_USE, $node->mask->contentUnits);
        self::assertSame(-0.1, $node->mask->x);
        self::assertSame(-0.1, $node->mask->y);
        self::assertSame(1.2, $node->mask->width);
        self::assertSame(1.2, $node->mask->height);
    }

    public function testUnknownMaskIdRendersWithoutMask(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<rect x="0" y="0" width="100" height="100" fill="red" mask="url(#nope)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $node = $meta->root->children[0];
        // Unknown id -> mask remains null -> no SvgMasked wrap.
        self::assertNotInstanceOf(SvgMasked::class, $node);
        self::assertInstanceOf(SvgRect::class, $node);
    }

    public function testMaskWithoutChildrenIsIgnored(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<defs><mask id="empty"/></defs>'
            . '<rect x="0" y="0" width="100" height="100" fill="red" mask="url(#empty)"/>'
            . '</svg>';
        $meta = Parser::parse($svg);
        $node = $meta->root->children[0];
        self::assertNotInstanceOf(SvgMasked::class, $node);
        self::assertInstanceOf(SvgRect::class, $node);
    }
}
