<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\BoundingBox;
use DragonOfMercy\PhpPdf\Svg\SvgCircle;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgPaint;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class BoundingBoxNodeTest extends TestCase
{
    public function testShapeNode(): void
    {
        $rect = new SvgRect(null, SvgPaint::default(), 5.0, 6.0, 20.0, 10.0, 0.0, 0.0);
        $bb = BoundingBox::ofNode($rect);
        self::assertSame(5.0, $bb->x);
        self::assertSame(6.0, $bb->y);
        self::assertSame(20.0, $bb->width);
        self::assertSame(10.0, $bb->height);
    }

    public function testGroupUnionWithChildTransform(): void
    {
        $a = new SvgRect(null, SvgPaint::default(), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $b = new SvgRect(SvgMatrix::translate(20.0, 0.0), SvgPaint::default(), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $group = new SvgGroup(null, [$a, $b]);
        $bb = BoundingBox::ofNode($group);
        self::assertSame(0.0, $bb->x);
        self::assertSame(0.0, $bb->y);
        self::assertSame(30.0, $bb->width);
        self::assertSame(10.0, $bb->height);
    }

    public function testEmptyGroupIsDegenerate(): void
    {
        $bb = BoundingBox::ofNode(new SvgGroup(null, []));
        self::assertTrue($bb->isDegenerate());
    }

    public function testCircleNode(): void
    {
        $c = new SvgCircle(null, SvgPaint::default(), 50.0, 50.0, 10.0);
        $bb = BoundingBox::ofNode($c);
        self::assertSame(40.0, $bb->x);
        self::assertSame(40.0, $bb->y);
        self::assertSame(20.0, $bb->width);
        self::assertSame(20.0, $bb->height);
    }
}
