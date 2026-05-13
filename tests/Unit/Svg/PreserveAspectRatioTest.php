<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Svg\Align;
use DragonOfMercy\PhpPdf\Svg\MeetOrSlice;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use PHPUnit\Framework\TestCase;

final class PreserveAspectRatioTest extends TestCase
{
    public function testDefaultIsXMidYMidMeet(): void
    {
        $d = PreserveAspectRatio::default();
        self::assertSame(Align::X_MID_Y_MID, $d->align);
        self::assertSame(MeetOrSlice::MEET, $d->meetOrSlice);
    }

    public function testParseSingleKeyword(): void
    {
        $p = PreserveAspectRatio::parse('xMinYMax');
        self::assertSame(Align::X_MIN_Y_MAX, $p->align);
        self::assertSame(MeetOrSlice::MEET, $p->meetOrSlice);
    }

    public function testParseTwoKeywords(): void
    {
        $p = PreserveAspectRatio::parse('xMaxYMin slice');
        self::assertSame(Align::X_MAX_Y_MIN, $p->align);
        self::assertSame(MeetOrSlice::SLICE, $p->meetOrSlice);
    }

    public function testParseNoneIgnoresMeetSlice(): void
    {
        $p = PreserveAspectRatio::parse('none');
        self::assertSame(Align::NONE, $p->align);
        self::assertSame(MeetOrSlice::MEET, $p->meetOrSlice);
    }

    public function testParseTrimsExtraWhitespace(): void
    {
        $p = PreserveAspectRatio::parse("  xMidYMid   meet  ");
        self::assertSame(Align::X_MID_Y_MID, $p->align);
        self::assertSame(MeetOrSlice::MEET, $p->meetOrSlice);
    }

    public function testParseInvalidAlignThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Invalid preserveAspectRatio alignment: 'xCoord'");
        PreserveAspectRatio::parse('xCoord meet');
    }

    public function testParseInvalidMeetOrSliceThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Invalid preserveAspectRatio meet-or-slice: 'cover'");
        PreserveAspectRatio::parse('xMidYMid cover');
    }
}
