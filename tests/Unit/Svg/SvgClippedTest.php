<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\ClipPathUnits;
use DragonOfMercy\PhpPdf\Svg\FillRule;
use DragonOfMercy\PhpPdf\Svg\SvgClip;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class SvgClippedTest extends TestCase
{
    public function testClipHoldsFields(): void
    {
        $shape = new SvgRect(null, SvgPaint::default(), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $clip = new SvgClip(ClipPathUnits::USER_SPACE_ON_USE, null, [$shape], FillRule::NONZERO);
        self::assertSame(ClipPathUnits::USER_SPACE_ON_USE, $clip->units);
        self::assertNull($clip->transform);
        self::assertSame([$shape], $clip->nodes);
        self::assertSame(FillRule::NONZERO, $clip->clipRule);
    }

    public function testClippedWrapsChildAndIsSvgNode(): void
    {
        $shape = new SvgRect(null, SvgPaint::default(), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $clip = new SvgClip(ClipPathUnits::OBJECT_BOUNDING_BOX, null, [], FillRule::EVENODD);
        $clipped = new SvgClipped($clip, $shape);
        self::assertInstanceOf(SvgNode::class, $clipped);
        self::assertSame($clip, $clipped->clip);
        self::assertSame($shape, $clipped->child);
    }
}
