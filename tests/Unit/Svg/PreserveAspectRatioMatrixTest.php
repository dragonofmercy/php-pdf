<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Align;
use DragonOfMercy\PhpPdf\Svg\MeetOrSlice;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\ViewBox;
use PHPUnit\Framework\TestCase;

final class PreserveAspectRatioMatrixTest extends TestCase
{
    private const float EPS = 1e-9;

    public function testNoneStretchesBothAxes(): void
    {
        $vb = new ViewBox(x: 0.0, y: 0.0, width: 10.0, height: 10.0);
        $par = new PreserveAspectRatio(Align::NONE, MeetOrSlice::MEET);
        $m = PreserveAspectRatio::matrixFor($vb, 20.0, 40.0, $par);
        self::assertEqualsWithDelta(2.0, $m->a, self::EPS);
        self::assertEqualsWithDelta(4.0, $m->d, self::EPS);
        self::assertEqualsWithDelta(0.0, $m->e, self::EPS);
        self::assertEqualsWithDelta(0.0, $m->f, self::EPS);
    }

    public function testMeetWithCenterAlignAddsOffset(): void
    {
        $vb = new ViewBox(x: 0.0, y: 0.0, width: 10.0, height: 10.0);
        $par = new PreserveAspectRatio(Align::X_MID_Y_MID, MeetOrSlice::MEET);
        $m = PreserveAspectRatio::matrixFor($vb, 20.0, 40.0, $par);
        self::assertEqualsWithDelta(2.0, $m->a, self::EPS);
        self::assertEqualsWithDelta(2.0, $m->d, self::EPS);
        self::assertEqualsWithDelta(0.0, $m->e, self::EPS);
        self::assertEqualsWithDelta(10.0, $m->f, self::EPS);
    }

    public function testSliceCoversTargetExceedingOneAxis(): void
    {
        $vb = new ViewBox(x: 0.0, y: 0.0, width: 10.0, height: 10.0);
        $par = new PreserveAspectRatio(Align::X_MID_Y_MID, MeetOrSlice::SLICE);
        $m = PreserveAspectRatio::matrixFor($vb, 20.0, 40.0, $par);
        self::assertEqualsWithDelta(4.0, $m->a, self::EPS);
        self::assertEqualsWithDelta(4.0, $m->d, self::EPS);
        self::assertEqualsWithDelta(-10.0, $m->e, self::EPS);
        self::assertEqualsWithDelta(0.0, $m->f, self::EPS);
    }

    public function testViewBoxWithOriginOffsetTranslatedAway(): void
    {
        $vb = new ViewBox(x: 5.0, y: 5.0, width: 10.0, height: 10.0);
        $par = new PreserveAspectRatio(Align::X_MID_Y_MID, MeetOrSlice::MEET);
        $m = PreserveAspectRatio::matrixFor($vb, 20.0, 20.0, $par);
        self::assertEqualsWithDelta(2.0, $m->a, self::EPS);
        self::assertEqualsWithDelta(2.0, $m->d, self::EPS);
        self::assertEqualsWithDelta(-10.0, $m->e, self::EPS);
        self::assertEqualsWithDelta(-10.0, $m->f, self::EPS);
    }

    public function testXMinAlignmentLeftEdge(): void
    {
        $vb = new ViewBox(x: 0.0, y: 0.0, width: 10.0, height: 10.0);
        $par = new PreserveAspectRatio(Align::X_MIN_Y_MID, MeetOrSlice::MEET);
        $m = PreserveAspectRatio::matrixFor($vb, 30.0, 10.0, $par);
        self::assertEqualsWithDelta(1.0, $m->a, self::EPS);
        self::assertEqualsWithDelta(1.0, $m->d, self::EPS);
        self::assertEqualsWithDelta(0.0, $m->e, self::EPS);
        self::assertEqualsWithDelta(0.0, $m->f, self::EPS);
    }

    public function testYMaxAlignmentBottom(): void
    {
        $vb = new ViewBox(x: 0.0, y: 0.0, width: 10.0, height: 10.0);
        $par = new PreserveAspectRatio(Align::X_MID_Y_MAX, MeetOrSlice::MEET);
        $m = PreserveAspectRatio::matrixFor($vb, 10.0, 30.0, $par);
        self::assertEqualsWithDelta(1.0, $m->a, self::EPS);
        self::assertEqualsWithDelta(1.0, $m->d, self::EPS);
        self::assertEqualsWithDelta(0.0, $m->e, self::EPS);
        self::assertEqualsWithDelta(20.0, $m->f, self::EPS);
    }
}
