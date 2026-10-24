<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Fill\Font\AppearanceFontFactory;
use DragonOfMercy\PhpPdf\Form\Fill\Font\Standard14AppearanceFont;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use PHPUnit\Framework\TestCase;

final class AppearanceFontFactoryTest extends TestCase
{
    private static function reader(): PdfReader
    {
        $doc = new Document();
        $doc->addPage();
        return PdfReader::fromBytes($doc->output());
    }

    private static function helv(?PdfObject $encoding): Dictionary
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Font'))
            ->withEntry(Name::of('Subtype'), Name::of('Type1'))
            ->withEntry(Name::of('BaseFont'), Name::of('Helvetica'));

        return $encoding === null ? $dict : $dict->withEntry(Name::of('Encoding'), $encoding);
    }

    private static function operand(Dictionary $drFont): string
    {
        $font = AppearanceFontFactory::forField(self::reader(), $drFont, 'Helvetica', 'fld', new MetricsRegistry());
        self::assertInstanceOf(Standard14AppearanceFont::class, $font);

        return $font->encodeShowOperand('„Zitat“ – 5 € • x');
    }

    public function testStandard14WithoutEncodingStaysWinAnsi(): void
    {
        self::assertSame("(\x84Zitat\x93 \x96 5 \x80 \x95 x)", self::operand(self::helv(null)));
    }

    public function testStandard14WithNamedEncodingStaysWinAnsi(): void
    {
        self::assertSame(
            "(\x84Zitat\x93 \x96 5 \x80 \x95 x)",
            self::operand(self::helv(Name::of('WinAnsiEncoding'))),
        );
    }

    public function testStandard14HonoursEncodingDifferences(): void
    {
        // Excerpt of the PDFDocEncoding /Differences Acrobat writes on the /Helv /DR font.
        $encoding = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Encoding'))
            ->withEntry(Name::of('Differences'), PdfArray::of(
                PdfNumber::ofInt(128),
                Name::of('bullet'), Name::of('dagger'), Name::of('daggerdbl'), Name::of('ellipsis'),
                Name::of('emdash'), Name::of('endash'), Name::of('florin'), Name::of('fraction'),
                Name::of('guilsinglleft'), Name::of('guilsinglright'), Name::of('minus'), Name::of('perthousand'),
                Name::of('quotedblbase'), Name::of('quotedblleft'), Name::of('quotedblright'),
                PdfNumber::ofInt(160),
                Name::of('Euro'),
            ));

        self::assertSame("(\x8CZitat\x8D \x85 5 \xA0 \x80 x)", self::operand(self::helv($encoding)));
    }

    public function testStandard14WithUnsupportedBaseEncodingFallsBackToWinAnsi(): void
    {
        $encoding = Dictionary::empty()
            ->withEntry(Name::of('BaseEncoding'), Name::of('MacRomanEncoding'))
            ->withEntry(Name::of('Differences'), PdfArray::of(PdfNumber::ofInt(128), Name::of('bullet')));

        self::assertSame("(\x84Zitat\x93 \x96 5 \x80 \x95 x)", self::operand(self::helv($encoding)));
    }
}
