<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Qr;

use DragonOfMercy\PhpPdf\Barcode\ErrorCorrection;
use DragonOfMercy\PhpPdf\Barcode\Qr\Encoder;
use DragonOfMercy\PhpPdf\Barcode\Qr\QrMode;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class EncoderTest extends TestCase
{
    public function testDetectModeNumeric(): void
    {
        self::assertSame(QrMode::Numeric, Encoder::detectMode('01234567'));
    }

    public function testDetectModeAlphanumeric(): void
    {
        self::assertSame(QrMode::Alphanumeric, Encoder::detectMode('HELLO WORLD'));
    }

    public function testDetectModeByteForLowercase(): void
    {
        self::assertSame(QrMode::Byte, Encoder::detectMode('hello'));
    }

    public function testIso18004AnnexI01234567IsVersion1ECM(): void
    {
        $result = Encoder::encode('01234567', ErrorCorrection::M);
        self::assertSame(1, $result->version);
        // Final codeword stream length for V1-M is 26 bytes (16 data + 10 EC).
        self::assertCount(26, $result->finalCodewords);
    }

    public function testCapacityExceededThrows(): void
    {
        // V10-H byte capacity is 119 bytes -- send well above it to force an overflow.
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/exceeds capacity of V10-H/');
        Encoder::encode(str_repeat('x', 500), ErrorCorrection::H);
    }
}
