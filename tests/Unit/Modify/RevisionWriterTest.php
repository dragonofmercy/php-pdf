<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify;

use DragonOfMercy\PhpPdf\Modify\RevisionWriter;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class RevisionWriterTest extends TestCase
{
    /** @return non-empty-string */
    private static function classicPdf(): string
    {
        $body = "%PDF-1.4\n";
        $o1 = strlen($body);
        $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $o2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 100 100] >>\nendobj\n";
        $o3 = strlen($body);
        $body .= "3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\n";
        $xrefAt = strlen($body);
        $body .= "xref\n0 4\n0000000000 65535 f \n"
            . sprintf("%010d 00000 n \n", $o1)
            . sprintf("%010d 00000 n \n", $o2)
            . sprintf("%010d 00000 n \n", $o3)
            . "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n{$xrefAt}\n%%EOF\n";
        return $body;
    }

    private static function xrefStreamPdf(): string
    {
        $body = "%PDF-1.5\n";
        $o1 = strlen($body);
        $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $o2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 100 100] >>\nendobj\n";
        $o3 = strlen($body);
        $body .= "3 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\n";
        $rows = "\x00\x00\x00\x00"
            . "\x01" . pack('n', $o1) . "\x00"
            . "\x01" . pack('n', $o2) . "\x00"
            . "\x01" . pack('n', $o3) . "\x00";
        $payload = gzcompress($rows, 9);
        assert(is_string($payload));
        $xrefAt = strlen($body);
        $body .= "4 0 obj\n<< /Type /XRef /Size 5 /W [1 2 1] /Index [0 4] /Root 1 0 R /Filter /FlateDecode /Length "
            . strlen($payload) . " >>\nstream\n" . $payload . "\nendstream\nendobj\nstartxref\n{$xrefAt}\n%%EOF\n";
        return $body;
    }

    public function testClassicSourceGetsAClassicRevision(): void
    {
        $prior = self::classicPdf();
        $reader = PdfReader::fromBytes($prior);
        $bytes = (new RevisionWriter())->append(
            reader: $reader,
            priorBytes: $prior,
            newObjects: [IndirectObject::of(4, 0, PdfString::of('new'))],
            trailerEntries: Dictionary::empty()->withEntry(Name::of('Root'), PdfReference::to(1, 0)),
            size: 5,
        );
        self::assertStringStartsWith($prior, $bytes);
        $tail = substr($bytes, strlen($prior));
        self::assertStringContainsString("\nxref\n", "\n" . $tail);     // classic table appended
        self::assertStringContainsString('trailer', $tail);
        self::assertStringContainsString('/Prev ' . $reader->lastStartxref(), $tail);
        $reopened = PdfReader::fromBytes($bytes);
        self::assertFalse($reopened->usesXrefStreams());
        self::assertEquals(PdfString::of('new'), $reopened->object(4));
    }

    public function testStreamSourceGetsAStreamRevision(): void
    {
        $prior = self::xrefStreamPdf();
        $reader = PdfReader::fromBytes($prior);
        $bytes = (new RevisionWriter())->append(
            reader: $reader,
            priorBytes: $prior,
            newObjects: [IndirectObject::of(5, 0, PdfString::of('new'))],
            trailerEntries: Dictionary::empty()->withEntry(Name::of('Root'), PdfReference::to(1, 0)),
            size: 6,
        );
        $tail = substr($bytes, strlen($prior));
        self::assertStringNotContainsString('trailer', $tail);          // no classic trailer
        self::assertStringContainsString('/Type /XRef', $tail);
        $reopened = PdfReader::fromBytes($bytes);
        self::assertTrue($reopened->usesXrefStreams());
        self::assertEquals(PdfString::of('new'), $reopened->object(5));
    }
}
