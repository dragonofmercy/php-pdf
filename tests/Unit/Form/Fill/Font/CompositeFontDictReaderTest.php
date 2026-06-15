<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Fill\Font\CompositeFontDictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use PHPUnit\Framework\TestCase;

final class CompositeFontDictReaderTest extends TestCase
{
    private static function reader(): PdfReader
    {
        $doc = new Document();
        $doc->addPage();
        return PdfReader::fromBytes($doc->output());
    }

    /**
     * Builds a minimal CIDFont dictionary for use as the /DescendantFonts entry.
     */
    private static function cidFontType2(?Dictionary $extra = null): Dictionary
    {
        $d = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Font'))
            ->withEntry(Name::of('Subtype'), Name::of('CIDFontType2'));
        if ($extra !== null) {
            $v = $extra->get(Name::of('CIDToGIDMap'));
            if ($v !== null) {
                $d = $d->withEntry(Name::of('CIDToGIDMap'), $v);
            }
        }
        return $d;
    }

    /**
     * Builds a base Type0 dict with Identity-H encoding and a CIDFontType2 descendant.
     * Pass $cidFont to override the descendant; pass $encoding to override /Encoding.
     */
    private static function type0Dict(?Name $encoding = null, ?Dictionary $cidFont = null): Dictionary
    {
        return Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Font'))
            ->withEntry(Name::of('Subtype'), Name::of('Type0'))
            ->withEntry(Name::of('Encoding'), $encoding ?? Name::of('Identity-H'))
            ->withEntry(Name::of('DescendantFonts'), PdfArray::of($cidFont ?? self::cidFontType2()));
    }

    // --- fail-fast tests ---

    public function testThrowsWhenEncodingIsNotIdentityH(): void
    {
        $dict = self::type0Dict(Name::of('WinAnsiEncoding'));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/fld/');

        CompositeFontDictReader::read($dict, self::reader(), 'fld');
    }

    public function testThrowsForCidFontType0Descendant(): void
    {
        $cidFontType0 = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Font'))
            ->withEntry(Name::of('Subtype'), Name::of('CIDFontType0'));

        $dict = self::type0Dict(null, $cidFontType0);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/fld/');

        CompositeFontDictReader::read($dict, self::reader(), 'fld');
    }

    public function testThrowsWhenCidToGidMapIsNotIdentity(): void
    {
        $cidFont = self::cidFontType2()
            ->withEntry(Name::of('CIDToGIDMap'), Name::of('SomethingElse'));

        $dict = self::type0Dict(null, $cidFont);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/fld/');

        CompositeFontDictReader::read($dict, self::reader(), 'fld');
    }
}
