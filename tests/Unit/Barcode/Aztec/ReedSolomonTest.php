<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Barcode\Aztec\GaloisField;
use DragonOfMercy\PhpPdf\Barcode\Aztec\ReedSolomon;
use PHPUnit\Framework\TestCase;

final class ReedSolomonTest extends TestCase
{
    /**
     * Mode message EC vector for Compact (ISO/IEC 24778 §A.3.1 example):
     * data codewords = [9, 0] for a 1-layer Compact with 8 data codewords,
     * 5 EC codewords expected (RS over GF(16)).
     *
     * The plan's originally-suggested vector [5, 12, 6, 14, 5] was incorrect.
     * The correct value [15, 6, 15, 2, 12] was derived by two independent methods:
     *   1. Polynomial long division of (9*x^6) by g(x) = x^5 + 11x^4 + 4x^3 + 6x^2 + 2x + 1
     *      over GF(16) with primitive p(x) = x^4 + x + 1 (0x13), yielding remainder
     *      15*x^4 + 6*x^3 + 15*x^2 + 2*x + 12 (high-degree-first).
     *   2. Evaluation of the full codeword polynomial C(x) = D(x)*x^5 + R(x) at each
     *      generator root alpha^1 through alpha^5 - all five evaluate to zero.
     * EC codewords are returned high-degree-first (matching Aztec transmission order).
     */
    public function testCompactModeMessageEcVector(): void
    {
        $ec = ReedSolomon::compute([9, 0], 5, GaloisField::gf16());
        self::assertSame([15, 6, 15, 2, 12], $ec);
    }

    public function testEcLengthMatchesRequest(): void
    {
        $ec = ReedSolomon::compute([1, 2, 3, 4, 5, 6, 7, 8], 4, GaloisField::gf256());
        self::assertCount(4, $ec);
    }

    public function testEmptyDataYieldsZeroEc(): void
    {
        $ec = ReedSolomon::compute([], 3, GaloisField::gf16());
        self::assertSame([0, 0, 0], $ec);
    }

    public function testCodewordValuesStayInsideField(): void
    {
        $gf = GaloisField::gf16();
        $ec = ReedSolomon::compute([1, 2, 3, 4], 6, $gf);
        foreach ($ec as $c) {
            self::assertGreaterThanOrEqual(0, $c);
            self::assertLessThan(16, $c);
        }
    }
}
