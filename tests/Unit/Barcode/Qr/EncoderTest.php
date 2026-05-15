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

    public function testV27AlphanumericUses13CharCountBits(): void
    {
        // 1600 alphanumeric chars land on V27-M (V26-M tops out at 1542 chars for
        // ErrorCorrection::M with our CAPACITY_TABLE; V27-M covers 1543-1638). Per ISO
        // 18004 Table 3, V27 alphanumeric MUST use a 13-bit char count. Without the
        // V27+ branch, charCountBits() falls back to 11 and the bitstream is malformed.
        //
        // The first interleaved codeword carries the mode indicator (4 bits) + the
        // most significant bits of the char count. 1600 in binary = "11001000000".
        //
        // Correct (13-bit char count):
        //   mode 0010 + count 0011001000000 -> first byte = 0b00100011 = 35
        // Buggy (11-bit char count):
        //   mode 0010 + count 11001000000   -> first byte = 0b00101100 = 44
        $payload = str_repeat('A', 1600);
        $result = Encoder::encode($payload, ErrorCorrection::M);
        self::assertSame(27, $result->version);
        self::assertSame(
            35,
            $result->finalCodewords[0],
            'V27 alphanumeric must use 13 char-count bits per ISO 18004 Table 3',
        );
    }

    public function testV40HasExpectedFinalCodewordCount(): void
    {
        // V40 total codewords (data + EC) = 3706 per ISO 18004 Table 1. This catches
        // ALIGNMENT/CAPACITY transcription that yields a wrong block layout (it would
        // produce a stream of different length).
        $payload = str_repeat('x', 2950);
        $result = Encoder::encode($payload, ErrorCorrection::L);
        self::assertSame(40, $result->version);
        self::assertCount(3706, $result->finalCodewords);
    }
}
