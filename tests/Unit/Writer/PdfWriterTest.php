<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\PdfWriter;
use PHPUnit\Framework\TestCase;

final class PdfWriterTest extends TestCase
{
    public function testHeaderIsPrefixed(): void
    {
        $catalog = IndirectObject::of(
            1,
            0,
            Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Catalog')),
        );
        $writer = new PdfWriter();
        $bytes = $writer->write([$catalog], $catalog->reference());
        self::assertStringStartsWith("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n", $bytes);
    }

    public function testTrailerEndsWithEof(): void
    {
        $catalog = IndirectObject::of(
            1,
            0,
            Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Catalog')),
        );
        $writer = new PdfWriter();
        $bytes = $writer->write([$catalog], $catalog->reference());
        self::assertStringEndsWith("%%EOF\n", $bytes);
    }

    public function testXrefOffsetPointsToXrefLine(): void
    {
        $catalog = IndirectObject::of(
            1,
            0,
            Dictionary::empty()->withEntry(Name::of('Type'), Name::of('Catalog')),
        );
        $writer = new PdfWriter();
        $bytes = $writer->write([$catalog], $catalog->reference());

        self::assertMatchesRegularExpression('/\nstartxref\n(\d+)\n%%EOF\n$/', $bytes);
        if (!preg_match('/\nstartxref\n(\d+)\n%%EOF\n$/', $bytes, $m)) {
            self::fail('Regex should match');
        }
        $xrefOffset = (int) $m[1];
        self::assertSame('xref', substr($bytes, $xrefOffset, 4));
    }
}
