<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Align;
use DragonOfMercy\PhpPdf\Svg\MeetOrSlice;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgImage;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use PHPUnit\Framework\TestCase;

final class SvgImageTest extends TestCase
{
    public function testIsAnSvgNodeWithAccessors(): void
    {
        $ar = new PreserveAspectRatio(Align::X_MID_Y_MID, MeetOrSlice::MEET);
        $img = new SvgImage(SvgMatrix::translate(1.0, 2.0), 10.0, 20.0, 80.0, 40.0, $ar, 0.5, 3, 16, 8);
        self::assertInstanceOf(SvgNode::class, $img);
        self::assertSame(10.0, $img->x);
        self::assertSame(20.0, $img->y);
        self::assertSame(80.0, $img->width);
        self::assertSame(40.0, $img->height);
        self::assertSame($ar, $img->aspectRatio);
        self::assertSame(0.5, $img->opacity);
        self::assertSame(3, $img->imageIndex);
        self::assertSame(16, $img->intrinsicWidth);
        self::assertSame(8, $img->intrinsicHeight);
        self::assertNotNull($img->transform);
    }

    public function testTransformNullable(): void
    {
        $img = new SvgImage(null, 0.0, 0.0, 1.0, 1.0, PreserveAspectRatio::default(), 1.0, 0, 2, 2);
        self::assertNull($img->transform);
    }
}
