<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Barcode\Aztec\EncodeResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EncodeResult DTO.
 *
 * Size formula verified against ZXing Encoder.java (Apache 2.0) and the
 * ISO/IEC 24778 anchor: Full Range layer 32 = 151x151 modules.
 */
final class EncodeResultTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Compact sizes: 11 + layers * 4
    // -----------------------------------------------------------------------

    public function testCompactLayer1Size(): void
    {
        $r = new EncodeResult(compact: true, layers: 1, codewordBits: 6, dataCodewords: [], ecCodewords: []);
        self::assertSame(15, $r->size());
    }

    public function testCompactLayer2Size(): void
    {
        $r = new EncodeResult(compact: true, layers: 2, codewordBits: 6, dataCodewords: [], ecCodewords: []);
        self::assertSame(19, $r->size());
    }

    public function testCompactLayer3Size(): void
    {
        $r = new EncodeResult(compact: true, layers: 3, codewordBits: 8, dataCodewords: [], ecCodewords: []);
        self::assertSame(23, $r->size());
    }

    public function testCompactLayer4Size(): void
    {
        $r = new EncodeResult(compact: true, layers: 4, codewordBits: 8, dataCodewords: [], ecCodewords: []);
        self::assertSame(27, $r->size());
    }

    // -----------------------------------------------------------------------
    // Full Range sizes: base = 14 + layers*4; size = base+1+2*intdiv(intdiv(base,2)-1, 15)
    // -----------------------------------------------------------------------

    public function testFullLayer1Size(): void
    {
        // base=18 -> 18+1+2*intdiv(8,15) = 19+0 = 19
        $r = new EncodeResult(compact: false, layers: 1, codewordBits: 6, dataCodewords: [], ecCodewords: []);
        self::assertSame(19, $r->size());
    }

    public function testFullLayer4Size(): void
    {
        // base=30 -> 30+1+2*intdiv(14,15) = 31+0 = 31
        $r = new EncodeResult(compact: false, layers: 4, codewordBits: 8, dataCodewords: [], ecCodewords: []);
        self::assertSame(31, $r->size());
    }

    public function testFullLayer5Size(): void
    {
        // base=34 -> 34+1+2*intdiv(16,15) = 35+2 = 37 (first reference-grid band)
        $r = new EncodeResult(compact: false, layers: 5, codewordBits: 8, dataCodewords: [], ecCodewords: []);
        self::assertSame(37, $r->size());
    }

    public function testFullLayer8Size(): void
    {
        // base=46 -> 46+1+2*intdiv(22,15) = 47+2 = 49
        $r = new EncodeResult(compact: false, layers: 8, codewordBits: 8, dataCodewords: [], ecCodewords: []);
        self::assertSame(49, $r->size());
    }

    public function testFullLayer9Size(): void
    {
        // base=50 -> 50+1+2*intdiv(24,15) = 51+2 = 53
        $r = new EncodeResult(compact: false, layers: 9, codewordBits: 10, dataCodewords: [], ecCodewords: []);
        self::assertSame(53, $r->size());
    }

    public function testFullLayer16Size(): void
    {
        // base=78 -> 78+1+2*intdiv(38,15) = 79+4 = 83
        $r = new EncodeResult(compact: false, layers: 16, codewordBits: 10, dataCodewords: [], ecCodewords: []);
        self::assertSame(83, $r->size());
    }

    public function testFullLayer17Size(): void
    {
        // base=82 -> 82+1+2*intdiv(40,15) = 83+4 = 87 (third reference-grid band)
        $r = new EncodeResult(compact: false, layers: 17, codewordBits: 10, dataCodewords: [], ecCodewords: []);
        self::assertSame(87, $r->size());
    }

    public function testFullLayer24Size(): void
    {
        // base=110 -> 110+1+2*intdiv(54,15) = 111+6 = 117
        $r = new EncodeResult(compact: false, layers: 24, codewordBits: 12, dataCodewords: [], ecCodewords: []);
        self::assertSame(117, $r->size());
    }

    public function testFullLayer32Size(): void
    {
        // base=142 -> 142+1+2*intdiv(70,15) = 143+8 = 151  (ISO/IEC 24778 anchor)
        $r = new EncodeResult(compact: false, layers: 32, codewordBits: 12, dataCodewords: [], ecCodewords: []);
        self::assertSame(151, $r->size());
    }

    // -----------------------------------------------------------------------
    // totalCodewords()
    // -----------------------------------------------------------------------

    public function testTotalCodewordsIsDataPlusEc(): void
    {
        $r = new EncodeResult(
            compact: true,
            layers: 1,
            codewordBits: 6,
            dataCodewords: [1, 2, 3, 4, 5],
            ecCodewords: [10, 11, 12],
        );
        self::assertSame(8, $r->totalCodewords());
    }

    public function testTotalCodewordsWithEmptyArrays(): void
    {
        $r = new EncodeResult(compact: false, layers: 4, codewordBits: 8, dataCodewords: [], ecCodewords: []);
        self::assertSame(0, $r->totalCodewords());
    }

    // -----------------------------------------------------------------------
    // Public property getters
    // -----------------------------------------------------------------------

    public function testPropertiesAreAccessible(): void
    {
        $data = [1, 2, 3];
        $ec = [7, 8];
        $r = new EncodeResult(compact: false, layers: 10, codewordBits: 10, dataCodewords: $data, ecCodewords: $ec);

        self::assertFalse($r->compact);
        self::assertSame(10, $r->layers);
        self::assertSame(10, $r->codewordBits);
        self::assertSame($data, $r->dataCodewords);
        self::assertSame($ec, $r->ecCodewords);
    }
}
