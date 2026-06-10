<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Filter\Ascii85Decoder;
use DragonOfMercy\PhpPdf\Reader\Filter\AsciiHexDecoder;
use DragonOfMercy\PhpPdf\Reader\Filter\RunLengthDecoder;
use DragonOfMercy\PhpPdf\Reader\Filter\StreamDecoder;
use DragonOfMercy\PhpPdf\Reader\Lexer;
use DragonOfMercy\PhpPdf\Reader\ObjectParser;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use PHPUnit\Framework\TestCase;

final class StreamDecoderTest extends TestCase
{
    private static function streamFromPdf(string $pdf): ReadStream
    {
        $object = (new ObjectParser(new Lexer($pdf)))->parseObject();
        self::assertInstanceOf(ReadStream::class, $object);
        return $object;
    }

    private static function identityResolve(): \Closure
    {
        return static fn (PdfObject $o): PdfObject => $o;
    }

    public function testAsciiHexDecode(): void
    {
        self::assertSame('Hello', AsciiHexDecoder::decode('48 65 6C6C 6F>'));
        self::assertSame("\xA0", AsciiHexDecoder::decode('A>'));   // odd: padded with 0
        self::assertSame('', AsciiHexDecoder::decode('>'));
    }

    public function testAsciiHexRejectsNonHex(): void
    {
        $this->expectException(PdfParseException::class);
        AsciiHexDecoder::decode('4G>');
    }

    public function testAscii85Decode(): void
    {
        self::assertSame('Man ', Ascii85Decoder::decode('9jqo^~>'));
        self::assertSame("\x00\x00\x00\x00", Ascii85Decoder::decode('z~>'));
        self::assertSame('Man', Ascii85Decoder::decode('9jqo~>'));        // partial final group
        self::assertSame('Man M', Ascii85Decoder::decode("9jqo^ 9` ~>")); // whitespace ignored
    }

    public function testAscii85RejectsInvalidCharacter(): void
    {
        $this->expectException(PdfParseException::class);
        Ascii85Decoder::decode('9jqo{~>');
    }

    public function testRunLengthDecode(): void
    {
        // 2 -> copy 3 literal bytes; 254 -> repeat next byte 257-254=3 times; 128 -> EOD
        $encoded = "\x02ABC\xFEz\x80";
        self::assertSame('ABCzzz', RunLengthDecoder::decode($encoded));
    }

    public function testStreamDecoderSingleFlateFilter(): void
    {
        $payload = gzcompress('content bytes', 9);
        self::assertIsString($payload);
        $pdf = '<< /Length ' . strlen($payload) . " /Filter /FlateDecode >>\nstream\n" . $payload . "\nendstream";
        $decoded = (new StreamDecoder())->decode(self::streamFromPdf($pdf), self::identityResolve());
        self::assertSame('content bytes', $decoded);
    }

    public function testStreamDecoderFilterChain(): void
    {
        $flate = gzcompress('chained', 9);
        self::assertIsString($flate);
        $hex = strtoupper(bin2hex($flate)) . '>';
        $pdf = '<< /Length ' . strlen($hex) . " /Filter [/ASCIIHexDecode /FlateDecode] >>\nstream\n" . $hex . "\nendstream";
        $decoded = (new StreamDecoder())->decode(self::streamFromPdf($pdf), self::identityResolve());
        self::assertSame('chained', $decoded);
    }

    public function testStreamDecoderHonorsDecodeParmsPredictor(): void
    {
        $rows = "\x02\x01\x02" . "\x02\x01\x01";   // PNG Up, columns=2
        $payload = gzcompress($rows, 9);
        self::assertIsString($payload);
        $pdf = '<< /Length ' . strlen($payload) . " /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 2 >> >>\nstream\n" . $payload . "\nendstream";
        $decoded = (new StreamDecoder())->decode(self::streamFromPdf($pdf), self::identityResolve());
        self::assertSame("\x01\x02\x02\x03", $decoded);
    }

    public function testStreamDecoderNoFilterReturnsRawData(): void
    {
        $pdf = "<< /Length 3 >>\nstream\nRAW\nendstream";
        $decoded = (new StreamDecoder())->decode(self::streamFromPdf($pdf), self::identityResolve());
        self::assertSame('RAW', $decoded);
    }

    public function testStreamDecoderRejectsUnsupportedFilter(): void
    {
        $pdf = "<< /Length 1 /Filter /DCTDecode >>\nstream\nX\nendstream";
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('DCTDecode');
        (new StreamDecoder())->decode(self::streamFromPdf($pdf), self::identityResolve());
    }
}
