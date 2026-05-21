<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\DataMatrix;

/**
 * Reed-Solomon error-correction codeword generator for DataMatrix ECC200.
 *
 * Operates over GF(256) with the DataMatrix primitive polynomial
 * p(x) = x^8 + x^5 + x^3 + x^2 + 1 (0x12D), per ISO/IEC 16022 5.7.1.
 *
 * @internal
 */
final class ReedSolomon
{
    private const int PRIMITIVE = 0x12D;
    private const int SIZE      = 256;

    /** @var list<int>|null */
    private static ?array $expTable = null;
    /** @var list<int>|null */
    private static ?array $logTable = null;

    /**
     * Compute the EC codewords for the given data codewords.
     *
     * @param list<int> $data             Data codewords (each 0-255).
     * @param int       $ecCodewordCount  Number of EC codewords to compute.
     * @return list<int>
     */
    public static function compute(array $data, int $ecCodewordCount): array
    {
        self::ensureTables();
        $exp = self::$expTable;
        $log = self::$logTable;
        assert($exp !== null && $log !== null);

        $generator = self::generatorPolynomial($ecCodewordCount);

        // Fixed-size remainder buffer, all zeros.
        $remainder = array_fill(0, $ecCodewordCount, 0);

        foreach ($data as $byte) {
            $factor = ($byte ^ $remainder[0]) & 0xFF;

            // Shift remainder left by one: remainder[i] = remainder[i+1].
            for ($k = 0; $k < $ecCodewordCount - 1; $k++) {
                $remainder[$k] = $remainder[$k + 1];
            }
            $remainder[$ecCodewordCount - 1] = 0;

            if ($factor !== 0) {
                $logFactor = $log[$factor];
                for ($i = 0; $i < $ecCodewordCount; $i++) {
                    // Generator stores coefficients constant-first (index 0)
                    // up to leading 1 at index $ecCodewordCount. The implicit
                    // leading 1 is consumed by the shift above, so we multiply
                    // by the remaining coefficients in reverse order.
                    $g = $generator[$ecCodewordCount - 1 - $i] & 0xFF;
                    if ($g === 0) {
                        continue;
                    }
                    $remainder[$i] = ($remainder[$i] ^ $exp[($logFactor + $log[$g]) % 255]) & 0xFF;
                }
            }
        }

        return array_values($remainder);
    }

    /**
     * Build the generator polynomial of degree $ecCount:
     *   g(x) = product over i in [1, ecCount] of (x + alpha^i)
     *
     * Coefficients are stored constant-first (index 0 = constant term,
     * index $ecCount = leading coefficient which is always 1).
     *
     * @return list<int> Coefficients, length = $ecCount + 1.
     */
    private static function generatorPolynomial(int $ecCount): array
    {
        $exp = self::$expTable;
        $log = self::$logTable;
        assert($exp !== null && $log !== null);
        $g = array_fill(0, $ecCount + 1, 0);
        $g[0] = 1;
        for ($i = 1; $i <= $ecCount; $i++) {
            for ($j = $i; $j > 0; $j--) {
                $prev = $g[$j - 1] & 0xFF;
                $curr = $g[$j] & 0xFF;
                if ($curr === 0) {
                    $g[$j] = $prev;
                } else {
                    $g[$j] = $prev ^ $exp[($log[$curr] + $i) % 255];
                }
            }
            $head = $g[0] & 0xFF;
            $g[0] = $exp[($log[$head] + $i) % 255];
        }
        return array_values($g);
    }

    private static function ensureTables(): void
    {
        if (self::$expTable !== null) {
            return;
        }
        $exp = array_fill(0, self::SIZE, 0);
        $log = array_fill(0, self::SIZE, 0);
        $x = 1;
        for ($i = 0; $i < self::SIZE - 1; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x >= self::SIZE) {
                $x ^= self::PRIMITIVE;
            }
        }
        $exp[self::SIZE - 1] = $exp[0];
        self::$expTable = array_values($exp);
        self::$logTable = array_values($log);
    }
}
