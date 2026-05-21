<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Barcode\Aztec\GaloisField;
use PHPUnit\Framework\TestCase;

final class GaloisFieldTest extends TestCase
{
    public function testGf16PrimitiveIsAlpha(): void
    {
        $gf = GaloisField::gf16();
        self::assertSame(1, $gf->exp(0));      // alpha^0 = 1
        self::assertSame(2, $gf->exp(1));      // alpha^1 = 2
        self::assertSame(4, $gf->exp(2));      // alpha^2 = 4
        self::assertSame(8, $gf->exp(3));      // alpha^3 = 8
        self::assertSame(3, $gf->exp(4));      // alpha^4 = alpha + 1 = 3 (since p(x) = x^4 + x + 1)
    }

    public function testGf16LogIsInverseOfExp(): void
    {
        $gf = GaloisField::gf16();
        for ($i = 1; $i < 16; $i++) {
            self::assertSame($i, $gf->exp($gf->log($i)));
        }
    }

    public function testGf16MultiplicationMatchesPolynomial(): void
    {
        $gf = GaloisField::gf16();
        // 3 * 5 in GF(16) with p(x)=x^4+x+1 should equal 15.
        self::assertSame(15, $gf->multiply(3, 5));
        // 0 * anything = 0
        self::assertSame(0, $gf->multiply(0, 7));
        // 1 * anything = anything
        self::assertSame(11, $gf->multiply(1, 11));
    }

    public function testGf256UsesAztecPrimitive(): void
    {
        // Aztec uses p(x) = x^8 + x^5 + x^3 + x^2 + 1 (0x12D), NOT QR's 0x11D.
        $gf = GaloisField::gf256();
        self::assertSame(2, $gf->exp(1));
        self::assertSame(4, $gf->exp(2));
        self::assertSame(128, $gf->exp(7));
        self::assertSame(45, $gf->exp(8)); // alpha^8 = 0x12D ^ 0x100 = 0x2D = 45
    }

    public function testAllFiveFieldsExposeCorrectSize(): void
    {
        self::assertSame(16,   GaloisField::gf16()->size());
        self::assertSame(64,   GaloisField::gf64()->size());
        self::assertSame(256,  GaloisField::gf256()->size());
        self::assertSame(1024, GaloisField::gf1024()->size());
        self::assertSame(4096, GaloisField::gf4096()->size());
    }

    public function testForCodewordBitsReturnsExpectedField(): void
    {
        self::assertSame(16,   GaloisField::forCodewordBits(4)->size());
        self::assertSame(64,   GaloisField::forCodewordBits(6)->size());
        self::assertSame(256,  GaloisField::forCodewordBits(8)->size());
        self::assertSame(1024, GaloisField::forCodewordBits(10)->size());
        self::assertSame(4096, GaloisField::forCodewordBits(12)->size());
    }
}
