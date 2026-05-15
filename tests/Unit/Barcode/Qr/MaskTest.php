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

    public function testFormatBitsArePlacedInCanonicalIsoOrder(): void
    {
        // ISO 18004 6.9: the 15-bit format string (index 0 = MSB) is placed
        // twice, and a compliant reader expects each bit at fixed positions.
        // Both copies MUST equal the format string read in this exact order,
        // and MUST be identical to each other. (Regression guard for the
        // copy-1 bit-reversal bug that made every QR undecodable.)
        $size = 21; // V1
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $ec = ErrorCorrection::M;
        $maskId = 2;
        Mask::placeFormatBits($modules, $ec, $maskId);
        $expected = Mask::formatBits($ec, $maskId);

        $copy1 = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        $copy2 = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1],
        ];

        $read1 = '';
        $read2 = '';
        for ($i = 0; $i < 15; $i++) {
            [$r1, $c1] = $copy1[$i];
            $read1 .= $modules[$r1][$c1] ? '1' : '0';
            [$r2, $c2] = $copy2[$i];
            $read2 .= $modules[$r2][$c2] ? '1' : '0';
        }

        self::assertSame($expected, $read1, 'Format copy 1 must follow canonical ISO 18004 6.9 order');
        self::assertSame($expected, $read2, 'Format copy 2 must follow canonical ISO 18004 6.9 order');
        self::assertSame($read1, $read2, 'Both format-info copies must be identical');
    }

    public function testFormatBitsCanonicalForEveryMaskAndEcLevel(): void
    {
        // Generalised guard: the placement must be canonical for ALL 8 masks
        // and ALL 4 EC levels, not just one combination. A reader must always
        // recover (EC, mask) from either copy.
        $size = 21;
        $copy1 = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8],
            [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
        ];
        $copy2 = [
            [$size - 1, 8], [$size - 2, 8], [$size - 3, 8], [$size - 4, 8],
            [$size - 5, 8], [$size - 6, 8], [$size - 7, 8],
            [8, $size - 8], [8, $size - 7], [8, $size - 6], [8, $size - 5],
            [8, $size - 4], [8, $size - 3], [8, $size - 2], [8, $size - 1],
        ];

        foreach ([ErrorCorrection::L, ErrorCorrection::M, ErrorCorrection::Q, ErrorCorrection::H] as $ec) {
            for ($maskId = 0; $maskId < 8; $maskId++) {
                $modules = array_fill(0, $size, array_fill(0, $size, false));
                Mask::placeFormatBits($modules, $ec, $maskId);
                $expected = Mask::formatBits($ec, $maskId);
                $r1 = '';
                $r2 = '';
                for ($i = 0; $i < 15; $i++) {
                    [$a, $b] = $copy1[$i];
                    $r1 .= $modules[$a][$b] ? '1' : '0';
                    [$d, $e] = $copy2[$i];
                    $r2 .= $modules[$d][$e] ? '1' : '0';
                }
                $ctx = "{$ec->value} mask {$maskId}";
                self::assertSame($expected, $r1, "copy 1 canonical for {$ctx}");
                self::assertSame($expected, $r2, "copy 2 canonical for {$ctx}");
            }
        }
    }
}
