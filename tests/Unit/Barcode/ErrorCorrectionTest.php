<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode;

use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;
use PHPUnit\Framework\TestCase;

final class ErrorCorrectionTest extends TestCase
{
    public function testFourLevels(): void
    {
        self::assertSame('L', ErrorCorrection::L->value);
        self::assertSame('M', ErrorCorrection::M->value);
        self::assertSame('Q', ErrorCorrection::Q->value);
        self::assertSame('H', ErrorCorrection::H->value);
        self::assertCount(4, ErrorCorrection::cases());
    }

    public function testFormatBitsPerSpec(): void
    {
        // ISO 18004 Table 12: format-info bits per EC level (used for QR format).
        self::assertSame(0b01, ErrorCorrection::L->formatBits());
        self::assertSame(0b00, ErrorCorrection::M->formatBits());
        self::assertSame(0b11, ErrorCorrection::Q->formatBits());
        self::assertSame(0b10, ErrorCorrection::H->formatBits());
    }
}
