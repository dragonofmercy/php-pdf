<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode\Qr;

/**
 * Reed-Solomon ECC encoder over GF(256), per ISO 18004 Section 6.5.
 *
 * @internal
 */
final class ReedSolomon
{
    /**
     * Generator polynomial of degree n: (x - a^0)(x - a^1) ... (x - a^(n-1)).
     * Coefficients returned high-to-low (length n+1, leading coefficient is 1).
     *
     * @return list<int<0, 255>>
     */
    public static function generator(int $n): array
    {
        /** @var list<int<0, 255>> $poly */
        $poly = [1];
        for ($i = 0; $i < $n; $i++) {
            // Multiply current poly by (x + a^i)
            // (in GF(256) char 2, addition = subtraction = xor)
            $polyLen = count($poly);
            /** @var list<int<0, 255>> $next */
            $next = array_fill(0, $polyLen + 1, 0);
            /** @var int<0, 255> $alphaI */
            $alphaI = GaloisField::exp($i) & 0xFF;
            for ($j = 0; $j < $polyLen; $j++) {
                /** @var int<0, 255> $coeff */
                $coeff = $poly[$j];
                $next[$j] = ($next[$j] ^ GaloisField::mul($coeff, 1)) & 0xFF;
                $next[$j + 1] = ($next[$j + 1] ^ GaloisField::mul($coeff, $alphaI)) & 0xFF;
            }
            $poly = $next;
        }
        return array_values($poly);
    }

    /**
     * Encode data codewords with `nEc` Reed-Solomon EC codewords.
     *
     * @param list<int<0, 255>> $data
     * @return list<int<0, 255>> The EC codewords (length nEc).
     */
    public static function encode(array $data, int $nEc): array
    {
        $gen = self::generator($nEc);
        // Buffer = data || zeros(nEc); we compute remainder of buffer mod gen.
        /** @var list<int<0, 255>> $buf */
        $buf = array_merge($data, array_fill(0, $nEc, 0));
        $dataLen = count($data);

        for ($i = 0; $i < $dataLen; $i++) {
            /** @var int<0, 255> $coef */
            $coef = $buf[$i];
            if ($coef === 0) {
                continue;
            }
            for ($j = 0; $j <= $nEc; $j++) {
                /** @var int<0, 255> $g */
                $g = $gen[$j];
                $buf[$i + $j] = ($buf[$i + $j] ^ GaloisField::mul($g, $coef)) & 0xFF;
            }
        }
        /** @var list<int<0, 255>> $result */
        $result = array_slice($buf, $dataLen, $nEc);
        return $result;
    }
}
