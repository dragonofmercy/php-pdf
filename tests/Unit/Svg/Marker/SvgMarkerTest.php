<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Marker;

use DragonOfMercy\PhpPdf\Svg\Marker\MarkerOrient;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerUnits;
use DragonOfMercy\PhpPdf\Svg\Marker\SvgMarker;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use PHPUnit\Framework\TestCase;

final class SvgMarkerTest extends TestCase
{
    public function testCarriesAllAttributes(): void
    {
        $m = new SvgMarker(
            viewBox: null,
            aspectRatio: PreserveAspectRatio::default(),
            markerWidth: 5.0,
            markerHeight: 6.0,
            refX: 1.0,
            refY: 2.0,
            units: MarkerUnits::USER_SPACE_ON_USE,
            orient: MarkerOrient::auto(),
            nodes: [],
        );
        self::assertSame(5.0, $m->markerWidth);
        self::assertSame(6.0, $m->markerHeight);
        self::assertSame(1.0, $m->refX);
        self::assertSame(2.0, $m->refY);
        self::assertSame(MarkerUnits::USER_SPACE_ON_USE, $m->units);
        self::assertSame([], $m->nodes);
    }
}
