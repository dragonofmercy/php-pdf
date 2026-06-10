<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer\Object;

use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use PHPUnit\Framework\TestCase;

final class PdfNumberTest extends TestCase
{
    public function testIntegerHasNoDecimals(): void
    {
        self::assertSame('42', PdfNumber::ofInt(42)->toBytes());
    }

    public function testNegativeIntegerIsPreserved(): void
    {
        self::assertSame('-5', PdfNumber::ofInt(-5)->toBytes());
    }

    public function testFloatTrimsTrailingZeros(): void
    {
        self::assertSame('595.28', PdfNumber::ofFloat(595.28)->toBytes());
    }

    public function testFloatThatIsWholeRendersWithoutDot(): void
    {
        self::assertSame('10', PdfNumber::ofFloat(10.0)->toBytes());
    }

    public function testZero(): void
    {
        self::assertSame('0', PdfNumber::ofInt(0)->toBytes());
        self::assertSame('0', PdfNumber::ofFloat(0.0)->toBytes());
    }

    public function testNumberExposesValue(): void
    {
        self::assertSame(42, PdfNumber::ofInt(42)->value());
        self::assertSame(2.5, PdfNumber::ofFloat(2.5)->value());
    }
}
