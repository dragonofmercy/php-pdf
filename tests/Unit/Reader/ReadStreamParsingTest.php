<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Lexer;
use DragonOfMercy\PhpPdf\Reader\ObjectParser;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class ReadStreamParsingTest extends TestCase
{
    public function testParsesStreamWithCorrectLength(): void
    {
        $pdf = "<< /Length 4 >>\nstream\nDATA\nendstream";
        $stream = (new ObjectParser(new Lexer($pdf)))->parseObject();
        self::assertInstanceOf(ReadStream::class, $stream);
        self::assertSame('DATA', $stream->rawData());
    }

    public function testParsesStreamWithCrlfAfterKeyword(): void
    {
        $pdf = "<< /Length 4 >>\nstream\r\nDATA\r\nendstream";
        $stream = (new ObjectParser(new Lexer($pdf)))->parseObject();
        self::assertInstanceOf(ReadStream::class, $stream);
        self::assertSame('DATA', $stream->rawData());
    }

    public function testBinaryDataWithEmbeddedEndstreamWordIsKeptWhenLengthIsRight(): void
    {
        $data = "abendstreamcd";
        $pdf = '<< /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
        $stream = (new ObjectParser(new Lexer($pdf)))->parseObject();
        self::assertInstanceOf(ReadStream::class, $stream);
        self::assertSame($data, $stream->rawData());
    }

    public function testWrongLengthFallsBackToEndstreamScan(): void
    {
        $pdf = "<< /Length 999 >>\nstream\nREAL DATA\nendstream";
        $stream = (new ObjectParser(new Lexer($pdf)))->parseObject();
        self::assertInstanceOf(ReadStream::class, $stream);
        self::assertSame('REAL DATA', $stream->rawData());
    }

    public function testIndirectLengthIsResolved(): void
    {
        $resolver = static fn (PdfReference $ref): PdfObject => PdfNumber::ofInt(4);
        $pdf = "<< /Length 9 0 R >>\nstream\nDATA\nendstream";
        $stream = (new ObjectParser(new Lexer($pdf), $resolver))->parseObject();
        self::assertInstanceOf(ReadStream::class, $stream);
        self::assertSame('DATA', $stream->rawData());
    }

    public function testIndirectLengthWithoutResolverFallsBackToScan(): void
    {
        $pdf = "<< /Length 9 0 R >>\nstream\nDATA\nendstream";
        $stream = (new ObjectParser(new Lexer($pdf)))->parseObject();
        self::assertInstanceOf(ReadStream::class, $stream);
        self::assertSame('DATA', $stream->rawData());
    }

    public function testMissingEndstreamThrows(): void
    {
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('endstream');
        (new ObjectParser(new Lexer("<< /Length 4 >>\nstream\nDATA")))->parseObject();
    }

    public function testToBytesRewritesLengthAndRoundTrips(): void
    {
        $pdf = "<< /Length 999 /Filter /FlateDecode >>\nstream\nXYZ\nendstream";
        $stream = (new ObjectParser(new Lexer($pdf)))->parseObject();
        self::assertInstanceOf(ReadStream::class, $stream);
        $bytes = $stream->toBytes();
        self::assertStringContainsString('/Length 3', $bytes);
        self::assertStringContainsString("stream\nXYZ\nendstream", $bytes);
        self::assertStringContainsString('/Filter /FlateDecode', $bytes);
    }

    public function testStreamInsideIndirectObject(): void
    {
        $pdf = "5 0 obj\n<< /Length 2 >>\nstream\nAB\nendstream\nendobj\n";
        $object = (new ObjectParser(new Lexer($pdf)))->parseIndirectObjectAt(0);
        self::assertInstanceOf(ReadStream::class, $object->payload());
    }

    public function testDictEntryAccess(): void
    {
        $pdf = "<< /Length 2 /N 3 >>\nstream\nAB\nendstream";
        $stream = (new ObjectParser(new Lexer($pdf)))->parseObject();
        self::assertInstanceOf(ReadStream::class, $stream);
        self::assertEquals(PdfNumber::ofInt(3), $stream->dict->get(Name::of('N')));
    }
}
