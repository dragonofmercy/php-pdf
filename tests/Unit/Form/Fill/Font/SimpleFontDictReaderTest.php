<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Fill\Font\SimpleFontDictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use PHPUnit\Framework\TestCase;

final class SimpleFontDictReaderTest extends TestCase
{
    private static function reader(): PdfReader
    {
        $doc = new Document();
        $doc->addPage();
        return PdfReader::fromBytes($doc->output());
    }

    public function testReadsWinAnsiWidths(): void
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Subtype'), Name::of('TrueType'))
            ->withEntry(Name::of('Encoding'), Name::of('WinAnsiEncoding'))
            ->withEntry(Name::of('FirstChar'), PdfNumber::ofInt(65))
            ->withEntry(Name::of('Widths'), PdfArray::of(PdfNumber::ofInt(700), PdfNumber::ofInt(800)));

        $prog = SimpleFontDictReader::read($dict, self::reader(), 'fld');

        self::assertSame(700, $prog->codeWidths[65]);
        self::assertSame(800, $prog->codeWidths[66]);
        self::assertSame(65, $prog->unicodeToCode[ord('A')]);
    }

    public function testAppliesDifferences(): void
    {
        $enc = Dictionary::empty()
            ->withEntry(Name::of('BaseEncoding'), Name::of('WinAnsiEncoding'))
            ->withEntry(Name::of('Differences'), PdfArray::of(PdfNumber::ofInt(200), Name::of('Euro')));
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Subtype'), Name::of('TrueType'))
            ->withEntry(Name::of('Encoding'), $enc)
            ->withEntry(Name::of('FirstChar'), PdfNumber::ofInt(200))
            ->withEntry(Name::of('Widths'), PdfArray::of(PdfNumber::ofInt(556)));

        $prog = SimpleFontDictReader::read($dict, self::reader(), 'fld');

        self::assertSame(200, $prog->unicodeToCode[0x20AC]); // Euro
        self::assertSame(556, $prog->codeWidths[200]);
    }

    public function testAppliesConsecutiveDifferences(): void
    {
        $enc = Dictionary::empty()
            ->withEntry(Name::of('BaseEncoding'), Name::of('WinAnsiEncoding'))
            ->withEntry(Name::of('Differences'), PdfArray::of(PdfNumber::ofInt(200), Name::of('Euro'), Name::of('florin')));
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Subtype'), Name::of('TrueType'))
            ->withEntry(Name::of('Encoding'), $enc)
            ->withEntry(Name::of('FirstChar'), PdfNumber::ofInt(200))
            ->withEntry(Name::of('Widths'), PdfArray::of(PdfNumber::ofInt(556), PdfNumber::ofInt(500)));

        $prog = SimpleFontDictReader::read($dict, self::reader(), 'fld');

        self::assertSame(200, $prog->unicodeToCode[0x20AC]); // Euro at code 200
        self::assertSame(201, $prog->unicodeToCode[0x0192]); // florin at code 201 (incremented)
    }

    public function testReadsMissingWidthFromFontDescriptor(): void
    {
        $descriptor = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('FontDescriptor'))
            ->withEntry(Name::of('MissingWidth'), PdfNumber::ofInt(250));
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Subtype'), Name::of('TrueType'))
            ->withEntry(Name::of('Encoding'), Name::of('WinAnsiEncoding'))
            ->withEntry(Name::of('FontDescriptor'), $descriptor)
            ->withEntry(Name::of('FirstChar'), PdfNumber::ofInt(65))
            ->withEntry(Name::of('Widths'), PdfArray::of(PdfNumber::ofInt(700)));

        $prog = SimpleFontDictReader::read($dict, self::reader(), 'fld');

        self::assertSame(250, $prog->missingWidth);
    }

    public function testMissingWidthDefaultsToZero(): void
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Subtype'), Name::of('TrueType'))
            ->withEntry(Name::of('Encoding'), Name::of('WinAnsiEncoding'))
            ->withEntry(Name::of('FirstChar'), PdfNumber::ofInt(65))
            ->withEntry(Name::of('Widths'), PdfArray::of(PdfNumber::ofInt(700)));

        $prog = SimpleFontDictReader::read($dict, self::reader(), 'fld');

        self::assertSame(0, $prog->missingWidth);
    }

    public function testThrowsForUnsupportedSubtype(): void
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Subtype'), Name::of('Type0'))
            ->withEntry(Name::of('Encoding'), Name::of('WinAnsiEncoding'))
            ->withEntry(Name::of('FirstChar'), PdfNumber::ofInt(32))
            ->withEntry(Name::of('Widths'), PdfArray::of(PdfNumber::ofInt(500)));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/fld/');

        SimpleFontDictReader::read($dict, self::reader(), 'fld');
    }
}
