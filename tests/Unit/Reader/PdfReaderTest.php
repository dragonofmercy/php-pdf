<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class PdfReaderTest extends TestCase
{
    /**
     * Assembles a single-revision classic PDF. $objects maps object number
     * to its payload source (without "N 0 obj"/"endobj"). $trailerExtra is
     * appended inside the trailer dict.
     *
     * @param non-empty-array<int, string> $objects
     */
    private static function buildPdf(array $objects, string $trailerExtra = '', string $header = "%PDF-1.6\n"): string
    {
        $body = $header;
        $offsets = [];
        foreach ($objects as $number => $payload) {
            $offsets[$number] = strlen($body);
            $body .= "{$number} 0 obj\n{$payload}\nendobj\n";
        }
        $size = max(array_keys($objects)) + 1;
        $xrefAt = strlen($body);
        $body .= "xref\n0 1\n0000000000 65535 f \n";
        foreach ($offsets as $number => $offset) {
            $body .= "{$number} 1\n" . sprintf("%010d 00000 n \n", $offset);
        }
        $body .= "trailer\n<< /Size {$size} /Root 1 0 R {$trailerExtra} >>\nstartxref\n{$xrefAt}\n%%EOF\n";
        return $body;
    }

    /** @return non-empty-array<int, string> */
    private static function defaultObjects(): array
    {
        return [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [] /Count 0 >>',
            3 => '(hello)',
        ];
    }

    public function testOpensAndExposesTrailerCatalogVersion(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf(self::defaultObjects()));
        self::assertEquals(PdfReference::to(1, 0), $reader->trailer()->get(Name::of('Root')));
        self::assertEquals(Name::of('Catalog'), $reader->catalog()->get(Name::of('Type')));
        self::assertSame('1.6', $reader->version());
    }

    public function testCatalogVersionOverridesHeader(): void
    {
        $objects = self::defaultObjects();
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R /Version /1.7 >>';
        $reader = PdfReader::fromBytes(self::buildPdf($objects));
        self::assertSame('1.7', $reader->version());
    }

    public function testObjectAndResolve(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf(self::defaultObjects()));
        self::assertEquals(PdfString::of('hello'), $reader->object(3));
        self::assertEquals(PdfString::of('hello'), $reader->resolve(PdfReference::to(3, 0)));
        self::assertEquals(PdfNumber::ofInt(5), $reader->resolve(PdfNumber::ofInt(5))); // non-refs pass through
    }

    public function testMissingObjectResolvesToNull(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf(self::defaultObjects()));
        self::assertSame(PdfNull::instance(), $reader->object(99));
    }

    public function testObjectCacheReturnsSameInstance(): void
    {
        $reader = PdfReader::fromBytes(self::buildPdf(self::defaultObjects()));
        self::assertSame($reader->object(3), $reader->object(3));
    }

    public function testJunkBeforeHeaderIsTolerated(): void
    {
        $pdf = "BLAH BLAH JUNK\n" . self::buildPdf(self::defaultObjects());
        $reader = PdfReader::fromBytes($pdf);
        self::assertEquals(PdfString::of('hello'), $reader->object(3));
    }

    public function testSlightlyWrongXrefOffsetIsRecoveredByScan(): void
    {
        $pdf = self::buildPdf(self::defaultObjects());
        // Insert junk bytes right before "3 0 obj" WITHOUT fixing the xref:
        // the recorded offset now points short of the real object, and the
        // junk is NOT a comment, so the exact-offset parse fails and the
        // recovery scan must kick in.
        $at = strpos($pdf, '3 0 obj');
        self::assertIsInt($at);
        $pdf = substr($pdf, 0, $at) . "xyz\n" . substr($pdf, $at);
        $reader = PdfReader::fromBytes($pdf);
        self::assertEquals(PdfString::of('hello'), $reader->object(3));
    }

    public function testMalformedEncryptDictionaryThrowsAtOpen(): void
    {
        // A /V 5 /R 6 dictionary with no /U /O /UE /OE cannot be authenticated;
        // the reader now attempts decryption (rather than refusing outright) and
        // fails loudly on the missing key material. End-to-end decryption of a
        // well-formed encrypted file is covered by PdfReaderEncryptedTest.
        $objects = self::defaultObjects();
        $objects[4] = '<< /Filter /Standard /V 5 /R 6 >>';
        $pdf = self::buildPdf($objects, '/Encrypt 4 0 R');
        $this->expectException(PdfException::class);
        PdfReader::fromBytes($pdf);
    }

    public function testMissingHeaderThrows(): void
    {
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('%PDF');
        PdfReader::fromBytes("not a pdf at all\n%%EOF\n");
    }

    public function testFromFileReadsAndNamesPathOnFailure(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phppdf_reader_');
        self::assertIsString($path);
        try {
            file_put_contents($path, self::buildPdf(self::defaultObjects()));
            $reader = PdfReader::fromFile($path);
            self::assertSame('1.6', $reader->version());
        } finally {
            @unlink($path);
        }

        $missing = $path . '.does-not-exist';
        try {
            PdfReader::fromFile($missing);
            self::fail('Expected PdfException');
        } catch (PdfException $exception) {
            self::assertStringContainsString($missing, $exception->getMessage());
        }
    }

    public function testObjectInsideObjectStreamIsResolved(): void
    {
        // object 6 lives in objstm 5; an xref stream maps it
        $packed = '(packed value)';
        $objStmData = '6 0 ' . $packed;
        $first = 4;
        $objStmPayload = gzcompress($objStmData, 9);
        self::assertIsString($objStmPayload);

        $body = "%PDF-1.5\n";
        $offsets = [];
        $offsets[1] = strlen($body);
        $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offsets[2] = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
        $offsets[5] = strlen($body);
        $body .= "5 0 obj\n<< /Type /ObjStm /N 1 /First {$first} /Filter /FlateDecode /Length " . strlen($objStmPayload) . " >>\nstream\n{$objStmPayload}\nendstream\nendobj\n";

        // xref stream: /W [1 2 1]
        $rows = "\x00\x00\x00\x00"
            . "\x01" . pack('n', $offsets[1]) . "\x00"
            . "\x01" . pack('n', $offsets[2]) . "\x00"
            . "\x00\x00\x00\x00"
            . "\x00\x00\x00\x00"
            . "\x01" . pack('n', $offsets[5]) . "\x00"
            . "\x02" . "\x00\x05" . "\x00";
        $xrefPayload = gzcompress($rows, 9);
        self::assertIsString($xrefPayload);
        $xrefAt = strlen($body);
        $body .= "7 0 obj\n<< /Type /XRef /Size 8 /W [1 2 1] /Index [0 7] /Root 1 0 R /Filter /FlateDecode /Length "
            . strlen($xrefPayload) . " >>\nstream\n{$xrefPayload}\nendstream\nendobj\nstartxref\n{$xrefAt}\n%%EOF\n";

        $reader = PdfReader::fromBytes($body);
        self::assertEquals(PdfString::of('packed value'), $reader->object(6));
    }

    public function testDecodeStreamDelegatesToFilters(): void
    {
        $payload = gzcompress('decoded ok', 9);
        self::assertIsString($payload);
        $objects = self::defaultObjects();
        $objects[4] = "<< /Length " . strlen($payload) . " /Filter /FlateDecode >>\nstream\n" . $payload . "\nendstream";
        $reader = PdfReader::fromBytes(self::buildPdf($objects));
        $stream = $reader->object(4);
        self::assertInstanceOf(ReadStream::class, $stream);
        self::assertSame('decoded ok', $reader->decodeStream($stream));
    }
}
