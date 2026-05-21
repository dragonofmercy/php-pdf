<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Aztec;

/**
 * Reed-Solomon encoder for Aztec Code.
 *
 * Computes systematic RS check codewords using the standard generator
 * polynomial g(x) = (x + alpha^1)(x + alpha^2)...(x + alpha^ecCount) over
 * the provided Galois field. Aztec uses this for both the mode message
 * (GF(16) with 5 or 6 EC codewords) and the data codewords (GF(64) through
 * GF(4096) depending on layer count).
 *
 * @internal
 */
final class ReedSolomon
{
    /**
     * Compute ecCount Reed-Solomon check codewords for the given data.
     *
     * The generator polynomial is g(x) = prod_{i=1}^{ecCount} (x + alpha^i).
     * The check codewords are the remainder of (data polynomial * x^ecCount)
     * mod g(x), computed via a standard LFSR shift-register, and are returned
     * high-degree-first (matching Aztec transmission order).
     *
     * @param list<int> $data
     * @return list<int>
     */
    public static function compute(array $data, int $ecCount, GaloisField $gf): array
    {
        if ($ecCount <= 0) {
            return [];
        }

        $generator = self::buildGenerator($ecCount, $gf);

        /** @var array<int, int> $remainder */
        $remainder = array_fill(0, $ecCount, 0);

        foreach ($data as $value) {
            $factor = $value ^ $remainder[0];
            // Shift the register left: r[i] = r[i+1] for i < ecCount-1, r[ecCount-1] = 0
            for ($i = 0; $i < $ecCount - 1; $i++) {
                $remainder[$i] = $remainder[$i + 1];
            }
            $remainder[$ecCount - 1] = 0;

            if ($factor !== 0) {
                $logFactor = $gf->log($factor);
                for ($i = 0; $i < $ecCount; $i++) {
                    if ($generator[$i] !== 0) {
                        $remainder[$i] ^= $gf->exp($gf->log($generator[$i]) + $logFactor);
                    }
                }
            }
        }

        /** @var list<int> $result */
        $result = array_values($remainder);
        return $result;
    }

    /**
     * Build the generator polynomial g(x) = prod_{i=1}^{ecCount} (x + alpha^i).
     *
     * Coefficients are stored high-degree-first (without the leading 1):
     * index 0 = coefficient of x^(ecCount-1), index ecCount-1 = constant term.
     * This ordering matches what the LFSR shift register in compute() expects.
     *
     * @return array<int, int>
     */
    private static function buildGenerator(int $ecCount, GaloisField $gf): array
    {
        // Start with poly = [1] (constant polynomial 1, high-degree-first)
        /** @var array<int, int> $poly */
        $poly = [1];
        for ($i = 1; $i <= $ecCount; $i++) {
            // Multiply current poly by (x + alpha^i).
            // poly is high-degree-first: poly[0] is the highest-degree coefficient.
            // Multiplying poly(x) by (x + alpha^i):
            //   new[j]   += poly[j] * 1         (shift left, multiply by x)
            //   new[j+1] += poly[j] * alpha^i   (multiply by constant term alpha^i)
            $polyLen = count($poly);
            /** @var array<int, int> $next */
            $next = array_fill(0, $polyLen + 1, 0);
            $alphaI = $gf->exp($i);
            for ($j = 0; $j < $polyLen; $j++) {
                $next[$j] ^= $poly[$j];
                $next[$j + 1] ^= $gf->multiply($poly[$j], $alphaI);
            }
            $poly = $next;
        }
        // $poly is now [1, g_{ecCount-1}, ..., g_0] (high-degree-first, including leading 1)
        // Drop the leading 1 (the x^ecCount term); it is not needed by the LFSR.
        // Use array_slice to avoid array_shift's type-narrowing issues with PHPStan.
        /** @var array<int, int> $result */
        $result = array_slice($poly, 1);
        return $result;
    }
}
