<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\XrefStreamWriter;
use PHPUnit\Framework\TestCase;

final class XrefStreamWriterTest extends TestCase
{
    /**
     * Minimal xref-stream source (same builder as PdfReaderRevisionInfoTest::xrefStreamPdf).
     *
     * @return non-empty-string
     */
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

    public function testAppendedRevisionRoundTripsThroughTheReader(): void
    {
        $prior = self::xrefStreamPdf();
        $reader = PdfReader::fromBytes($prior);

        $newObject = IndirectObject::of(5, 0, PdfString::of('appended'));
        $rewrittenCatalog = IndirectObject::of(1, 0, Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Catalog'))
            ->withEntry(Name::of('Pages'), PdfReference::to(2, 0))
            ->withEntry(Name::of('Marker'), PdfString::of('v2')));

        $trailerEntries = Dictionary::empty()->withEntry(Name::of('Root'), PdfReference::to(1, 0));
        $bytes = (new XrefStreamWriter())->append(
            priorBytes: $prior,
            newObjects: [$rewrittenCatalog, $newObject],
            trailerEntries: $trailerEntries,
            prevStartxref: $reader->lastStartxref(),
            size: 6,                     // xref stream object becomes 6, /Size 7
        );

        self::assertStringStartsWith($prior, $bytes);   // original untouched
        $reopened = PdfReader::fromBytes($bytes);
        self::assertTrue($reopened->usesXrefStreams());
        self::assertEquals(PdfString::of('appended'), $reopened->object(5));
        $catalog = $reopened->catalog();
        self::assertEquals(PdfString::of('v2'), $catalog->get(Name::of('Marker')));   // newest revision wins
        self::assertSame(1, $reopened->pageCount());                                   // old objects still reachable
    }

    public function testNonContiguousNumbersProduceIndexSubsections(): void
    {
        $prior = self::xrefStreamPdf();
        $reader = PdfReader::fromBytes($prior);
        $bytes = (new XrefStreamWriter())->append(
            priorBytes: $prior,
            newObjects: [
                IndirectObject::of(1, 0, PdfString::of('one')),
                IndirectObject::of(5, 0, PdfString::of('five')),
            ],
            trailerEntries: Dictionary::empty()->withEntry(Name::of('Root'), PdfReference::to(1, 0)),
            prevStartxref: $reader->lastStartxref(),
            size: 6,
        );
        $reopened = PdfReader::fromBytes($bytes);
        self::assertEquals(PdfString::of('one'), $reopened->object(1));
        self::assertEquals(PdfString::of('five'), $reopened->object(5));
        // /Index must enumerate three subsections: 1, 5, and the stream itself (6)
        self::assertStringContainsString('/Index [1 1 5 2]', $bytes); // 5..6 contiguous
    }

    public function testIdAndInfoAreCarriedVerbatim(): void
    {
        $prior = self::xrefStreamPdf();
        $reader = PdfReader::fromBytes($prior);
        $trailerEntries = Dictionary::empty()
            ->withEntry(Name::of('Root'), PdfReference::to(1, 0))
            ->withEntry(Name::of('ID'), PdfArray::of(
                HexString::of('AA11'),
                HexString::of('BB22'),
            ));
        $bytes = (new XrefStreamWriter())->append($prior, [IndirectObject::of(5, 0, PdfString::of('x'))], $trailerEntries, $reader->lastStartxref(), 6);
        self::assertStringContainsString('/ID [<AA11> <BB22>]', $bytes);
        self::assertStringContainsString('/Prev ' . $reader->lastStartxref(), $bytes);
        self::assertStringContainsString('/Size 7', $bytes);
    }
}
