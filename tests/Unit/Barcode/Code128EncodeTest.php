<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\BarcodeKind;
use DragonOfMercy\PhpPdf\Barcode\Code128;
use DragonOfMercy\PhpPdf\Barcode\Orientation;
use DragonOfMercy\PhpPdf\Barcode\TextAnchor;
use PHPUnit\Framework\TestCase;

final class Code128EncodeTest extends TestCase
{
    public function testEncodeReturnsLinear1d(): void
    {
        $c = Code128::of('HELLO');
        $enc = $c->encode();
        self::assertSame(BarcodeKind::LINEAR_1D, $enc->kind);
        self::assertSame(Orientation::Horizontal, $enc->orientation);
        self::assertGreaterThan(20, count($enc->modules));
        self::assertFalse($enc->modules[0]);
        self::assertFalse($enc->modules[count($enc->modules) - 1]);
    }

    public function testCenteredTextSegmentWhenShowTextDefault(): void
    {
        $c = Code128::of('ABC');
        $enc = $c->encode();
        self::assertCount(1, $enc->humanTextSegments);
        $seg = $enc->humanTextSegments[0];
        self::assertSame('ABC', $seg->text);
        self::assertSame(TextAnchor::MIDDLE, $seg->anchor);
        self::assertEqualsWithDelta(count($enc->modules) / 2.0, $seg->xModule, 0.001);
    }

    public function testNoTextSegmentsWhenWithoutText(): void
    {
        $c = Code128::of('ABC')->withoutText();
        self::assertSame([], $c->encode()->humanTextSegments);
    }

    public function testVerticalOrientationCarriedThrough(): void
    {
        $c = Code128::of('ABC')->vertical();
        self::assertSame(Orientation::Vertical, $c->encode()->orientation);
    }
}
