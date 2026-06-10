<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\XrefEntryKind;
use DragonOfMercy\PhpPdf\Reader\XrefReader;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class XrefClassicTest extends TestCase
{
    /** Minimal single-revision classic PDF skeleton; xref at a known offset. */
    private static function minimalPdf(): string
    {
        $body = "%PDF-1.4\n";
        $offsets = [];
        $offsets[1] = strlen($body);
        $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $offsets[2] = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
        $xrefAt = strlen($body);
        $body .= "xref\n0 3\n";
        $body .= "0000000000 65535 f \n";
        $body .= sprintf("%010d 00000 n \n", $offsets[1]);
        $body .= sprintf("%010d 00000 n \n", $offsets[2]);
        $body .= "trailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n{$xrefAt}\n%%EOF\n";
        return $body;
    }

    public function testReadsSingleClassicRevision(): void
    {
        $pdf = self::minimalPdf();
        $data = (new XrefReader($pdf, 0))->read();

        self::assertSame(XrefEntryKind::Free, $data->entries[0]->kind);
        self::assertSame(XrefEntryKind::InFile, $data->entries[1]->kind);
        self::assertSame(XrefEntryKind::InFile, $data->entries[2]->kind);
        $expectedOffset = strpos($pdf, '1 0 obj');
        self::assertSame($expectedOffset, $data->entries[1]->first);
        self::assertSame(0, $data->entries[1]->second);
        self::assertEquals(PdfReference::to(1, 0), $data->trailer->get(Name::of('Root')));
    }

    public function testMultipleSubsections(): void
    {
        $pdf = "%PDF-1.4\nxref\n0 1\n0000000000 65535 f \n5 2\n0000000100 00000 n \n0000000200 00001 n \ntrailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n9\n%%EOF\n";
        $data = (new XrefReader($pdf, 0))->read();
        self::assertArrayHasKey(0, $data->entries);
        self::assertArrayHasKey(5, $data->entries);
        self::assertArrayHasKey(6, $data->entries);
        self::assertArrayNotHasKey(1, $data->entries);
        self::assertSame(100, $data->entries[5]->first);
        self::assertSame(200, $data->entries[6]->first);
        self::assertSame(1, $data->entries[6]->second);
    }

    public function testHeaderOffsetShiftsXrefOffsets(): void
    {
        $junk = "GARBAGE-PREFIX\n";
        $pdf = self::minimalPdf();
        // startxref values inside the file are relative to the %PDF header
        $data = (new XrefReader($junk . $pdf, strlen($junk)))->read();
        self::assertSame(XrefEntryKind::InFile, $data->entries[1]->kind);
        self::assertSame(strpos($pdf, '1 0 obj'), $data->entries[1]->first);
    }

    public function testMissingStartxrefThrows(): void
    {
        $this->expectException(PdfParseException::class);
        $this->expectExceptionMessage('startxref');
        (new XrefReader("%PDF-1.4\nno xref here\n%%EOF\n", 0))->read();
    }

    public function testGarbageAtXrefOffsetThrows(): void
    {
        $pdf = "%PDF-1.4\nNOT AN XREF\nstartxref\n9\n%%EOF\n";
        $this->expectException(PdfParseException::class);
        (new XrefReader($pdf, 0))->read();
    }
}
