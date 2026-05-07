<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;
use DragonOfMercy\PhpPdf\Barcode\Qr\Mask;
use PHPUnit\Framework\TestCase;

final class MaskTest extends TestCase
{
    public function testMaskFunction0IsRowPlusColMod2Eq0(): void
    {
        // Mask 0: (row + col) mod 2 == 0
        self::assertTrue(Mask::condition(0, 0, 0));
        self::assertFalse(Mask::condition(0, 0, 1));
        self::assertTrue(Mask::condition(0, 1, 1));
    }

    public function testFormatBitsForLAndMask0(): void
    {
        // Per ISO 18004 Table 12, level L mask 0 = 0b111011111000100
        $bits = Mask::formatBits(ErrorCorrection::L, 0);
        self::assertSame('111011111000100', $bits);
    }

    public function testFormatBitsForMAndMask3(): void
    {
        // Per ISO 18004 Table C.1, level M mask 3 = 0b101101101001011
        // (data5 = 0b00011, BCH remainder = 0b1101011001, XOR 0b101010000010010)
        $bits = Mask::formatBits(ErrorCorrection::M, 3);
        self::assertSame('101101101001011', $bits);
    }

    public function testScoringDetectsLongRuns(): void
    {
        // 5 consecutive dark in row -> N1 penalty = 3.
        $m = array_fill(0, 21, array_fill(0, 21, false));
        for ($c = 0; $c < 5; $c++) {
            $m[0][$c] = true;
        }
        $score = Mask::score($m);
        self::assertGreaterThanOrEqual(3, $score);
    }

    public function testVersionBitsForV7(): void
    {
        // ISO 18004 Annex D Table D.1 -- V7 = 0b000111110010010100 (decimal 7 then BCH parity)
        $bits = Mask::versionBits(7);
        self::assertSame(18, strlen($bits));
        self::assertSame('000111110010010100', $bits);
    }
}
