<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\Qr\ReedSolomon;
use PHPUnit\Framework\TestCase;

final class ReedSolomonTest extends TestCase
{
    public function testGeneratorDegree2(): void
    {
        // g(x) = (x - a^0)(x - a^1) = x^2 - (a^0 + a^1) x + a^0 a^1
        // a^0 = 1, a^1 = 2. Sum (xor) = 3. Product = 2. Coefficients (high to low): [1, 3, 2].
        $g = ReedSolomon::generator(2);
        self::assertSame([1, 3, 2], $g);
    }

    public function testEncodeIso18004AnnexI(): void
    {
        // ISO 18004 Annex I.2: data codewords for "01234567" V1-M, 10 EC codewords.
        // Data codewords: hex 10 20 0C 56 61 80 EC 11 EC 11 EC 11 EC 11 EC 11
        // EC codewords verified via syndrome check (S_0..S_9 = 0) and cross-checked
        // against the Nayuki QR reference implementation.
        $data = [0x10, 0x20, 0x0C, 0x56, 0x61, 0x80, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11, 0xEC, 0x11];
        $ec = ReedSolomon::encode($data, 10);
        self::assertSame([0xA5, 0x24, 0xD4, 0xC1, 0xED, 0x36, 0xC7, 0x87, 0x2C, 0x55], $ec);
    }
}
