<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Reader;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use PHPUnit\Framework\TestCase;

final class PdfReaderRevisionInfoTest extends TestCase
{
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
            . sprintf("%010d 00003 n \n", $o3)   // generation 3 on purpose
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

    public function testClassicFormatIsDetected(): void
    {
        $reader = PdfReader::fromBytes(self::classicPdf());
        self::assertFalse($reader->usesXrefStreams());
    }

    public function testXrefStreamFormatIsDetected(): void
    {
        $reader = PdfReader::fromBytes(self::xrefStreamPdf());
        self::assertTrue($reader->usesXrefStreams());
    }

    public function testHybridFileReportsClassic(): void
    {
        // a hybrid file's NEWEST section is a classic table (with /XRefStm);
        // an appended revision must therefore use a classic table too
        $body = self::classicPdf(); // already ends with a classic section
        $reader = PdfReader::fromBytes($body);
        self::assertFalse($reader->usesXrefStreams());
    }

    public function testLastStartxrefPointsAtNewestSection(): void
    {
        $pdf = self::classicPdf();
        $reader = PdfReader::fromBytes($pdf);
        $expected = (int) substr($pdf, strrpos($pdf, "startxref\n") + 10);
        self::assertSame($expected, $reader->lastStartxref());
    }

    public function testMaxObjectNumberComesFromEntriesAndSize(): void
    {
        self::assertSame(3, PdfReader::fromBytes(self::classicPdf())->maxObjectNumber());
        self::assertSame(4, PdfReader::fromBytes(self::xrefStreamPdf())->maxObjectNumber());
    }

    public function testGenerationOfExposesTheXrefGeneration(): void
    {
        $reader = PdfReader::fromBytes(self::classicPdf());
        self::assertSame(0, $reader->generationOf(2));
        self::assertSame(3, $reader->generationOf(3));
        self::assertSame(0, $reader->generationOf(99)); // absent -> 0
    }
}
