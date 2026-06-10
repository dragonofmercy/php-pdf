<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\XrefEntryKind;
use DragonOfMercy\PhpPdf\Reader\XrefReader;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class XrefStreamTest extends TestCase
{
    /**
     * Builds "%PDF-1.5 ... 99 0 obj << xref-stream dict >> stream ... endstream endobj startxref ..."
     * @param list<string> $rows binary rows, each already w1+w2+w3 bytes
     */
    private static function pdfWithXrefStream(array $rows, string $w, int $size, string $extraDictEntries = ''): string
    {
        $body = "%PDF-1.5\n";
        $payload = gzcompress(implode('', $rows), 9);
        assert(is_string($payload));
        $xrefAt = strlen($body);
        $dict = '<< /Type /XRef /Size ' . $size . ' /W ' . $w
            . ' /Root 1 0 R /Filter /FlateDecode /Length ' . strlen($payload) . ' ' . $extraDictEntries . ' >>';
        $body .= "99 0 obj\n" . $dict . "\nstream\n" . $payload . "\nendstream\nendobj\n";
        $body .= "startxref\n{$xrefAt}\n%%EOF\n";
        return $body;
    }

    public function testReadsXrefStreamEntries(): void
    {
        // /W [1 2 1]: type(1) offset/objstm(2) gen/index(1)
        $rows = [
            "\x00" . "\x00\x00" . "\x00",   // obj 0: free
            "\x01" . "\x00\x0F" . "\x00",   // obj 1: in file at offset 15, gen 0
            "\x02" . "\x00\x05" . "\x03",   // obj 2: in objstm 5, index 3
        ];
        $pdf = self::pdfWithXrefStream($rows, '[1 2 1]', 3);
        $data = (new XrefReader($pdf, 0))->read();

        self::assertSame(XrefEntryKind::Free, $data->entries[0]->kind);
        self::assertSame(XrefEntryKind::InFile, $data->entries[1]->kind);
        self::assertSame(15, $data->entries[1]->first);
        self::assertSame(XrefEntryKind::InObjectStream, $data->entries[2]->kind);
        self::assertSame(5, $data->entries[2]->first);
        self::assertSame(3, $data->entries[2]->second);
        self::assertEquals(PdfReference::to(1, 0), $data->trailer->get(Name::of('Root')));
    }

    public function testIndexSubsectionsAreHonored(): void
    {
        // /Index [2 1 10 2]: rows describe objects 2, then 10 and 11
        $rows = [
            "\x01" . "\x00\x64" . "\x00",
            "\x01" . "\x00\xC8" . "\x00",
            "\x01" . "\x01\x2C" . "\x05",
        ];
        $pdf = self::pdfWithXrefStream($rows, '[1 2 1]', 12, '/Index [2 1 10 2]');
        $data = (new XrefReader($pdf, 0))->read();
        self::assertSame(100, $data->entries[2]->first);
        self::assertSame(200, $data->entries[10]->first);
        self::assertSame(300, $data->entries[11]->first);
        self::assertSame(5, $data->entries[11]->second);
        self::assertArrayNotHasKey(3, $data->entries);
    }

    public function testZeroWidthTypeFieldDefaultsToInFile(): void
    {
        // /W [0 2 1]: type column absent -> defaults to 1 (in file)
        $rows = ["\x00\x10" . "\x00"];
        $pdf = self::pdfWithXrefStream($rows, '[0 2 1]', 1, '/Index [4 1]');
        $data = (new XrefReader($pdf, 0))->read();
        self::assertSame(XrefEntryKind::InFile, $data->entries[4]->kind);
        self::assertSame(16, $data->entries[4]->first);
    }

    public function testPredictorEncodedXrefStream(): void
    {
        // Same entries as testReadsXrefStreamEntries but PNG Up-predicted, /W [1 2 1] => rowLen 4
        $plain = [
            "\x00\x00\x00\x00",
            "\x01\x00\x0F\x00",
            "\x02\x00\x05\x03",
        ];
        $encoded = '';
        $previous = "\x00\x00\x00\x00";
        foreach ($plain as $row) {
            $line = "\x02"; // Up filter
            for ($i = 0; $i < 4; $i++) {
                $line .= chr((ord($row[$i]) - ord($previous[$i])) & 0xFF);
            }
            $encoded .= $line;
            $previous = $row;
        }
        $payload = gzcompress($encoded, 9);
        self::assertIsString($payload);
        $body = "%PDF-1.5\n";
        $xrefAt = strlen($body);
        $body .= "99 0 obj\n<< /Type /XRef /Size 3 /W [1 2 1] /Root 1 0 R /Filter /FlateDecode"
            . ' /DecodeParms << /Predictor 12 /Columns 4 >> /Length ' . strlen($payload) . " >>\nstream\n"
            . $payload . "\nendstream\nendobj\nstartxref\n{$xrefAt}\n%%EOF\n";
        $data = (new XrefReader($body, 0))->read();
        self::assertSame(15, $data->entries[1]->first);
        self::assertSame(XrefEntryKind::InObjectStream, $data->entries[2]->kind);
    }

    public function testTruncatedXrefStreamThrows(): void
    {
        $rows = ["\x01\x00"]; // too short for /W [1 2 1]
        $pdf = self::pdfWithXrefStream($rows, '[1 2 1]', 1);
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('xref stream');
        (new XrefReader($pdf, 0))->read();
    }

    public function testMissingWThrows(): void
    {
        $payload = gzcompress("\x01\x00\x0F\x00", 9);
        self::assertIsString($payload);
        $body = "%PDF-1.5\n";
        $xrefAt = strlen($body);
        $body .= "99 0 obj\n<< /Type /XRef /Size 1 /Root 1 0 R /Filter /FlateDecode /Length " . strlen($payload) . " >>\nstream\n"
            . $payload . "\nendstream\nendobj\nstartxref\n{$xrefAt}\n%%EOF\n";
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('/W');
        (new XrefReader($body, 0))->read();
    }
}
