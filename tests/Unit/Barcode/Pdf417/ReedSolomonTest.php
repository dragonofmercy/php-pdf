<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Pdf417;

use DragonOfMercy\PhpPdf\Barcode\Pdf417\ReedSolomon;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class ReedSolomonTest extends TestCase
{
    /**
     * The generator polynomial coefficients (constant term first, without the
     * leading 1) must match zxing's precomputed EC_COEFFICIENTS (ISO/IEC 15438).
     */
    public function testGeneratorCoefficientsMatchZxing(): void
    {
        self::assertSame([27, 917], ReedSolomon::generatorCoefficients(2));
        self::assertSame([522, 568, 723, 809], ReedSolomon::generatorCoefficients(4));
        self::assertSame(
            [237, 308, 436, 284, 646, 653, 428, 379],
            ReedSolomon::generatorCoefficients(8),
        );
    }

    public function testEcCountMatchesRequested(): void
    {
        foreach ([2, 4, 8, 16, 32, 64, 128, 256, 512] as $n) {
            self::assertCount($n, ReedSolomon::compute([1, 2, 3], $n));
        }
    }

    public function testAllEcCodewordsInRange(): void
    {
        $ec = ReedSolomon::compute(range(1, 20), 32);
        foreach ($ec as $c) {
            self::assertGreaterThanOrEqual(0, $c);
            self::assertLessThan(929, $c);
        }
    }

    public function testEmptyDataYieldsZeroEc(): void
    {
        self::assertSame([0, 0], ReedSolomon::compute([], 2));
    }

    public function testComputeRejectsNonPowerOfTwoEcCount(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/power of two/');
        ReedSolomon::compute([1, 2, 3], 3);
    }

    public function testComputeRejectsOutOfRangeEcCount(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/power of two/');
        ReedSolomon::compute([1, 2, 3], 1024);
    }

    public function testGeneratorCoefficientsRejectInvalidEcCount(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/power of two/');
        ReedSolomon::generatorCoefficients(0);
    }
}
