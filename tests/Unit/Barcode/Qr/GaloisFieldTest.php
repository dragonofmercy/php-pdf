<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\Qr\GaloisField;
use PHPUnit\Framework\TestCase;

final class GaloisFieldTest extends TestCase
{
    public function testExpAndLogAreInverses(): void
    {
        for ($i = 1; $i < 255; $i++) {
            $v = GaloisField::exp($i);
            self::assertSame($i, GaloisField::log($v), "log(exp({$i})) should be {$i}");
        }
    }

    public function testMulZeroIsZero(): void
    {
        self::assertSame(0, GaloisField::mul(0, 5));
        self::assertSame(0, GaloisField::mul(5, 0));
        self::assertSame(0, GaloisField::mul(0, 0));
    }

    public function testMulOneIsIdentity(): void
    {
        for ($v = 1; $v < 256; $v++) {
            self::assertSame($v, GaloisField::mul(1, $v));
            self::assertSame($v, GaloisField::mul($v, 1));
        }
    }

    public function testMulKnownValues(): void
    {
        // From any GF(256) reference: 2 * 2 = 4, 2 * 4 = 8, 2 * 128 = 29 (because of mod poly).
        self::assertSame(4, GaloisField::mul(2, 2));
        self::assertSame(8, GaloisField::mul(2, 4));
        self::assertSame(29, GaloisField::mul(2, 128));
    }
}
