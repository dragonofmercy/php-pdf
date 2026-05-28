<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\BarcodeKind;
use DragonOfMercy\PhpPdf\Barcode\Ean13;
use DragonOfMercy\PhpPdf\Barcode\TextAnchor;
use PHPUnit\Framework\TestCase;

final class Ean13EncodeTest extends TestCase
{
    public function testEncodeReturns113Modules(): void
    {
        $e = Ean13::of('978013110362');
        $enc = $e->encode();
        self::assertSame(BarcodeKind::LINEAR_1D, $enc->kind);
        // 11 left quiet + 95 bars + 7 right quiet = 113.
        self::assertCount(113, $enc->modules);
    }

    public function testThreeTextSegmentsInOfficialLayout(): void
    {
        $e = Ean13::of('978013110362');
        $segments = $e->encode()->humanTextSegments;
        self::assertCount(3, $segments);
        // Segment 0: leading digit, anchor=START in the left quiet zone.
        self::assertSame('9', $segments[0]->text);
        // Segment 1: 6 digits, anchor=MIDDLE centered in padded 14..55.
        self::assertSame('780131', $segments[1]->text);
        self::assertSame(TextAnchor::MIDDLE, $segments[1]->anchor);
        self::assertEqualsWithDelta(35.0, $segments[1]->xModule, 0.001);
        // Segment 2: 6 digits, anchor=MIDDLE centered in padded 61..102.
        // (Trailing 7 is the auto-computed mod-10 checksum.)
        self::assertSame('103627', $segments[2]->text);
        self::assertEqualsWithDelta(82.0, $segments[2]->xModule, 0.001);
    }

    public function testNoTextSegmentsWhenWithoutText(): void
    {
        self::assertSame([], Ean13::of('978013110362')->withoutText()->encode()->humanTextSegments);
    }
}
