<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Image;

use DragonOfMercy\PhpPdf\Image\SvgMetadata;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\ViewBox;
use PHPUnit\Framework\TestCase;

final class SvgMetadataTest extends TestCase
{
    public function testConstructorStoresFields(): void
    {
        $vb = new ViewBox(0.0, 0.0, 24.0, 24.0);
        $ar = PreserveAspectRatio::default();
        $root = new SvgGroup(null, []);
        $m = new SvgMetadata($vb, $ar, $root);

        self::assertSame($vb, $m->viewBox);
        self::assertSame($ar, $m->aspectRatio);
        self::assertSame($root, $m->root);
    }

    public function testIntrinsicWidthDelegatesToViewBox(): void
    {
        $m = new SvgMetadata(
            new ViewBox(0.0, 0.0, 100.5, 50.25),
            PreserveAspectRatio::default(),
            new SvgGroup(null, []),
        );
        self::assertSame(100.5, $m->intrinsicWidth());
        self::assertSame(50.25, $m->intrinsicHeight());
    }
}
