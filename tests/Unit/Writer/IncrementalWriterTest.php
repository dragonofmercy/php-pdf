<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer;

use DragonOfMercy\PhpPdf\Writer\IncrementalWriter;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class IncrementalWriterTest extends TestCase
{
    public function testAppendsRevisionWithPrevAndAbsoluteOffsets(): void
    {
        $rev1 = "%PDF-1.7\nbody...\nstartxref\n42\n%%EOF\n";
        $newObj = IndirectObject::of(
            5,
            0,
            Dictionary::empty()->withEntry(Name::of('Type'), Name::of('DocTimeStamp')),
        );

        $out = (new IncrementalWriter())->append(
            priorBytes: $rev1,
            newObjects: [$newObj],
            root: PdfReference::to(1, 0),
            documentId: 'deadbeef',
            prevStartxref: 42,
            size: 6,
        );

        self::assertStringStartsWith($rev1, $out);
        $objOffset = strpos($out, "5 0 obj");
        self::assertNotFalse($objOffset);
        self::assertGreaterThanOrEqual(strlen($rev1), $objOffset);
        self::assertStringContainsString("xref\n5 1\n", $out);
        self::assertStringContainsString('/Prev 42', $out);
        self::assertStringContainsString('/Size 6', $out);
        self::assertStringContainsString('/ID [<DEADBEEF> <DEADBEEF>]', $out);
        if (preg_match('~startxref\n(\d+)\n%%EOF\n$~', $out, $m) !== 1) {
            self::fail('final startxref not found');
        }
        self::assertGreaterThanOrEqual(strlen($rev1), (int) $m[1]);
    }
}
