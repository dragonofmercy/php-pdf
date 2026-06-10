<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Reader\XrefEntryKind;
use DragonOfMercy\PhpPdf\Reader\XrefReader;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use PHPUnit\Framework\TestCase;

final class XrefChainTest extends TestCase
{
    /** Two classic revisions: rev2 overrides object 1 and adds object 3. */
    private static function twoRevisionPdf(): string
    {
        $body = "%PDF-1.4\n";
        $xref1 = strlen($body);
        $body .= "xref\n0 3\n"
            . "0000000000 65535 f \n"
            . "0000000100 00000 n \n"
            . "0000000200 00000 n \n"
            . "trailer\n<< /Size 3 /Root 1 0 R /Info 2 0 R >>\nstartxref\n{$xref1}\n%%EOF\n";
        $xref2 = strlen($body);
        $body .= "xref\n0 1\n0000000000 65535 f \n1 1\n0000000900 00000 n \n3 1\n0000000950 00000 n \n"
            . "trailer\n<< /Size 4 /Root 1 0 R /Prev {$xref1} >>\nstartxref\n{$xref2}\n%%EOF\n";
        return $body;
    }

    public function testNewestRevisionWinsAndOlderObjectsRemain(): void
    {
        $data = (new XrefReader(self::twoRevisionPdf(), 0))->read();
        self::assertSame(900, $data->entries[1]->first);  // overridden by rev2
        self::assertSame(200, $data->entries[2]->first);  // from rev1
        self::assertSame(950, $data->entries[3]->first);  // added in rev2
    }

    public function testTrailerMergesFirstSeenWins(): void
    {
        $data = (new XrefReader(self::twoRevisionPdf(), 0))->read();
        // /Size comes from the NEWEST revision; /Info only exists in rev1
        self::assertEquals(PdfNumber::ofInt(4), $data->trailer->get(Name::of('Size')));
        self::assertNotNull($data->trailer->get(Name::of('Info')));
    }

    public function testPrevLoopIsDetectedAndStops(): void
    {
        // single revision whose /Prev points at itself: must terminate
        $body = "%PDF-1.4\n";
        $xref1 = strlen($body);
        $body .= "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<< /Size 1 /Root 1 0 R /Prev {$xref1} >>\nstartxref\n{$xref1}\n%%EOF\n";
        $data = (new XrefReader($body, 0))->read();
        self::assertSame(XrefEntryKind::Free, $data->entries[0]->kind);
    }

    public function testHybridXRefStmTakesPrecedenceOverPrev(): void
    {
        // Revision 1 (classic): object 5 at offset 111.
        // Hybrid main section: classic table marks only object 0; /XRefStm maps 5 into an objstm.
        $body = "%PDF-1.5\n";
        $xref1 = strlen($body);
        $body .= "xref\n5 1\n0000000111 00000 n \n"
            . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref1}\n%%EOF\n";

        $rows = "\x02" . "\x00\x09" . "\x00";   // obj 5 -> objstm 9 index 0 ; /W [1 2 1]
        $payload = gzcompress($rows, 9);
        assert(is_string($payload));
        $xrefStmAt = strlen($body);
        $body .= "99 0 obj\n<< /Type /XRef /Size 6 /W [1 2 1] /Index [5 1] /Root 1 0 R /Filter /FlateDecode /Length "
            . strlen($payload) . " >>\nstream\n" . $payload . "\nendstream\nendobj\n";

        $xref2 = strlen($body);
        $body .= "xref\n0 1\n0000000000 65535 f \n"
            . "trailer\n<< /Size 6 /Root 1 0 R /Prev {$xref1} /XRefStm {$xrefStmAt} >>\nstartxref\n{$xref2}\n%%EOF\n";

        $data = (new XrefReader($body, 0))->read();
        self::assertSame(XrefEntryKind::InObjectStream, $data->entries[5]->kind);
        self::assertSame(9, $data->entries[5]->first);
    }

    public function testChainAcrossStreamAndClassicSections(): void
    {
        // rev1 classic, rev2 an xref stream whose /Prev points at rev1
        $body = "%PDF-1.5\n";
        $xref1 = strlen($body);
        $body .= "xref\n0 2\n0000000000 65535 f \n0000000123 00000 n \n"
            . "trailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n{$xref1}\n%%EOF\n";
        $rows = "\x01" . "\x01\xC8" . "\x00";  // obj 2 in file at 456 ; /W [1 2 1]
        $payload = gzcompress($rows, 9);
        assert(is_string($payload));
        $xref2 = strlen($body);
        $body .= "99 0 obj\n<< /Type /XRef /Size 3 /W [1 2 1] /Index [2 1] /Root 1 0 R /Prev {$xref1} /Filter /FlateDecode /Length "
            . strlen($payload) . " >>\nstream\n" . $payload . "\nendstream\nendobj\nstartxref\n{$xref2}\n%%EOF\n";

        $data = (new XrefReader($body, 0))->read();
        self::assertSame(123, $data->entries[1]->first);
        self::assertSame(456, $data->entries[2]->first);
    }
}
