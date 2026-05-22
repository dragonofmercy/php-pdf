<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Pdf417;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Reed-Solomon error-correction generator for PDF417 (ISO/IEC 15438 8.6).
 *
 * Operates over the prime field GF(929): arithmetic is modulo 929, the
 * generator polynomial of degree N has roots 3^1 .. 3^N (mod 929), where 3 is
 * a primitive root of 929. EC codeword count is 2^(level+1) for levels 0-8.
 *
 * Algorithm and the generator-polynomial reference (zxing EC_COEFFICIENTS) are
 * ported from zxing PDF417ErrorCorrection (Apache 2.0).
 *
 * @internal
 */
final class ReedSolomon
{
    private const int MODULUS = 929;

    /**
     * Compute the EC codewords for the given data codewords.
     *
     * @param list<int> $data    Data codewords (each 0-928).
     * @param int       $ecCount Number of EC codewords (power of two, 2..512).
     * @return list<int>         EC codewords in symbol order (length = $ecCount).
     */
    public static function compute(array $data, int $ecCount): array
    {
        self::assertValidEcCount($ecCount);
        $generator = self::generatorCoefficients($ecCount);
        $ec = array_fill(0, $ecCount, 0);
        foreach ($data as $d) {
            $t = ($d + $ec[0]) % self::MODULUS;
            for ($j = 0; $j < $ecCount; $j++) {
                $next = ($j + 1 < $ecCount) ? $ec[$j + 1] : 0;
                $ec[$j] = ($next - ($t * $generator[$ecCount - 1 - $j]) % self::MODULUS + self::MODULUS) % self::MODULUS;
            }
        }
        for ($j = 0; $j < $ecCount; $j++) {
            $ec[$j] = $ec[$j] === 0 ? 0 : self::MODULUS - $ec[$j];
        }
        /** @var list<int> $ec */
        return $ec;
    }

    /**
     * Generator polynomial coefficients (constant term first, WITHOUT the
     * leading 1), the product of (x - 3^i) for i in 1..$ecCount over GF(929).
     * Matches zxing's precomputed EC_COEFFICIENTS.
     *
     * @return list<int>
     */
    public static function generatorCoefficients(int $ecCount): array
    {
        self::assertValidEcCount($ecCount);
        $poly = [1]; // constant-term-first; index k = coefficient of x^k
        $root = 1;
        for ($i = 0; $i < $ecCount; $i++) {
            $root = ($root * 3) % self::MODULUS;
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $k => $coeff) {
                // multiply by (x - root): contributes -root*coeff at x^k and +coeff at x^(k+1)
                $next[$k] = ($next[$k] - ($root * $coeff) % self::MODULUS + self::MODULUS) % self::MODULUS;
                $next[$k + 1] = ($next[$k + 1] + $coeff) % self::MODULUS;
            }
            $poly = $next;
        }
        // Drop the leading 1 (highest-degree term at index $ecCount).
        $coefficients = array_slice($poly, 0, $ecCount);
        /** @var list<int> $coefficients */
        return $coefficients;
    }

    /**
     * EC codeword counts are 2^(level+1) for levels 0-8, i.e. a power of two in
     * 2..512. Reject anything else: the GF(929) generator and EC blocks are only
     * defined for those counts, and a bad count yields an undecodable symbol.
     *
     * @throws PdfException
     */
    private static function assertValidEcCount(int $ecCount): void
    {
        if ($ecCount < 2 || $ecCount > 512 || ($ecCount & ($ecCount - 1)) !== 0) {
            throw new PdfException(sprintf(
                'PDF417 EC codeword count must be a power of two in 2-512, got %d',
                $ecCount,
            ));
        }
    }
}
