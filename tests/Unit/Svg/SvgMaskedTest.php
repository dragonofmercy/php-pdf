<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Mask\MaskUnits;
use DragonOfMercy\PhpPdf\Svg\Mask\SvgMask;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class SvgMaskedTest extends TestCase
{
    public function testConstruction(): void
    {
        $mask = new SvgMask('m', MaskUnits::OBJECT_BOUNDING_BOX, MaskUnits::USER_SPACE_ON_USE, -0.1, -0.1, 1.2, 1.2, []);
        $child = new SvgRect(null, SvgPaint::default(), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $node = new SvgMasked($mask, $child);
        self::assertInstanceOf(SvgNode::class, $node);
        self::assertSame($mask, $node->mask);
        self::assertSame($child, $node->child);
    }
}
