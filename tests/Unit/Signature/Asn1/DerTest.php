<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Asn1;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use PHPUnit\Framework\TestCase;

final class DerTest extends TestCase
{
    public function testEncodeLengthShortForm(): void
    {
        self::assertSame("\x00", Der::encodeLength(0));
        self::assertSame("\x7F", Der::encodeLength(127));
    }

    public function testEncodeLengthLongForm(): void
    {
        self::assertSame("\x81\x80", Der::encodeLength(128));
        self::assertSame("\x82\x01\x00", Der::encodeLength(256));
        self::assertSame("\x82\xFF\xFF", Der::encodeLength(65535));
    }

    public function testTlvWrapsTagLengthValue(): void
    {
        self::assertSame("\x04\x03abc", Der::tlv(0x04, 'abc'));
    }

    public function testSequenceAndSetWrap(): void
    {
        self::assertSame("\x30\x03abc", Der::sequence('abc'));
        self::assertSame("\x31\x03abc", Der::set('abc'));
    }

    public function testIntegerEncodesUnsignedMagnitudeWithLeadingZeroWhenHighBitSet(): void
    {
        self::assertSame("\x02\x01\x01", Der::integer(1));
        self::assertSame("\x02\x01\x7F", Der::integer(127));
        // 128 has the high bit set, needs a leading 0x00 to stay positive.
        self::assertSame("\x02\x02\x00\x80", Der::integer(128));
        self::assertSame("\x02\x01\x00", Der::integer(0));
    }

    public function testBooleanTrueIsFF(): void
    {
        self::assertSame("\x01\x01\xFF", Der::boolean(true));
        self::assertSame("\x01\x01\x00", Der::boolean(false));
    }

    public function testOctetStringAndNull(): void
    {
        self::assertSame("\x04\x02\xDE\xAD", Der::octetString("\xDE\xAD"));
        self::assertSame("\x05\x00", Der::null());
    }

    public function testOidEncoding(): void
    {
        // 1.2.840.113549.1.7.2 (id-signedData)
        self::assertSame(
            "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x02",
            Der::oid('1.2.840.113549.1.7.2'),
        );
        // 2.16.840.1.101.3.4.2.1 (sha-256), first arc 2 -> 40*2+16 = 96 = 0x60
        self::assertSame(
            "\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01",
            Der::oid('2.16.840.1.101.3.4.2.1'),
        );
    }

    public function testContextConstructedTag(): void
    {
        // [0] constructed wrapping "ab"
        self::assertSame("\xA0\x02ab", Der::contextConstructed(0, 'ab'));
        self::assertSame("\xA1\x02ab", Der::contextConstructed(1, 'ab'));
    }

    public function testReadHeaderShortForm(): void
    {
        $h = Der::readHeader("\x30\x03abc", 0);
        self::assertSame(0x30, $h['tag']);
        self::assertSame(3, $h['length']);
        self::assertSame(2, $h['valueStart']);
        self::assertSame(5, $h['end']);
    }

    public function testReadHeaderLongForm(): void
    {
        $data = "\x04\x82\x01\x00" . str_repeat('x', 256);
        $h = Der::readHeader($data, 0);
        self::assertSame(0x04, $h['tag']);
        self::assertSame(256, $h['length']);
        self::assertSame(4, $h['valueStart']);
        self::assertSame(260, $h['end']);
    }

    public function testReadHeaderRejectsTruncated(): void
    {
        $this->expectException(PdfException::class);
        Der::readHeader("\x30\x05ab", 0);
    }

    public function testReadHeaderRejectsIndefiniteLength(): void
    {
        $this->expectException(PdfException::class);
        Der::readHeader("\x30\x80", 0);
    }
}
