<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg\Marker;

use DragonOfMercy\PhpPdf\Svg\Marker\MarkerOrient;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerSet;
use DragonOfMercy\PhpPdf\Svg\Marker\MarkerUnits;
use DragonOfMercy\PhpPdf\Svg\Marker\SvgMarker;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use PHPUnit\Framework\TestCase;

final class MarkerSetTest extends TestCase
{
    private function someMarker(): SvgMarker
    {
        return new SvgMarker(null, PreserveAspectRatio::default(), 3.0, 3.0, 0.0, 0.0, MarkerUnits::STROKE_WIDTH, MarkerOrient::angle(0.0), []);
    }

    public function testEmptyHasAllNulls(): void
    {
        $s = MarkerSet::empty();
        self::assertNull($s->start);
        self::assertNull($s->mid);
        self::assertNull($s->end);
    }

    public function testWithStart(): void
    {
        $m = $this->someMarker();
        $s = MarkerSet::empty()->withStart($m);
        self::assertSame($m, $s->start);
        self::assertNull($s->mid);
        self::assertNull($s->end);
    }

    public function testWithAllThree(): void
    {
        $m = $this->someMarker();
        $s = MarkerSet::empty()->withStart($m)->withMid($m)->withEnd($m);
        self::assertSame($m, $s->start);
        self::assertSame($m, $s->mid);
        self::assertSame($m, $s->end);
    }
}
