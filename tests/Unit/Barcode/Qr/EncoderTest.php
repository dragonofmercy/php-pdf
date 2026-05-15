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

    public function testV11EncodesAtV11Numeric(): void
    {
        // ISO 18004 V10-M numeric capacity = 513 digits, V11-M numeric capacity = 604 digits.
        // 520 digits overflows V10-M and should land on V11-M.
        $payload = str_repeat('0123456789', 52); // 520 digits
        $result = Encoder::encode($payload, ErrorCorrection::M);
        self::assertSame(11, $result->version);
    }

    public function testV40EncodesAtV40Byte(): void
    {
        // V40-L byte capacity is 2953 bytes. Send 2950 bytes -> picks V40-L.
        $payload = str_repeat('x', 2950);
        $result = Encoder::encode($payload, ErrorCorrection::L);
        self::assertSame(40, $result->version);
    }
}
