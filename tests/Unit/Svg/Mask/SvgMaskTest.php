<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Mask;

use DragonOfMercy\PhpPdf\Svg\Mask\MaskUnits;
use DragonOfMercy\PhpPdf\Svg\Mask\SvgMask;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class SvgMaskTest extends TestCase
{
    public function testConstruction(): void
    {
        $child = new SvgRect(null, \DragonOfMercy\PhpPdf\Svg\SvgPaint::default(), 0.0, 0.0, 10.0, 10.0, 0.0, 0.0);
        $mask = new SvgMask(
            id: 'm1',
            units: MaskUnits::OBJECT_BOUNDING_BOX,
            contentUnits: MaskUnits::USER_SPACE_ON_USE,
            x: -0.1, y: -0.1, width: 1.2, height: 1.2,
            nodes: [$child],
        );
        self::assertSame('m1', $mask->id);
        self::assertSame(MaskUnits::OBJECT_BOUNDING_BOX, $mask->units);
        self::assertSame(MaskUnits::USER_SPACE_ON_USE, $mask->contentUnits);
        self::assertSame(-0.1, $mask->x);
        self::assertSame(-0.1, $mask->y);
        self::assertSame(1.2, $mask->width);
        self::assertSame(1.2, $mask->height);
        self::assertCount(1, $mask->nodes);
    }
}
