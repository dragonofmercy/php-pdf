<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Font\Custom\CompositeFontEmitter;
use DragonOfMercy\PhpPdf\Font\Custom\SubsettedFont;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;

/**
 * Builds a minimal source PDF whose /AcroForm /DR /Font /F1 is an embedded
 * Type0/CIDFontType2/Identity-H font (FreeSans.ttf) and whose single text
 * field /DA is "/F1 10 Tf". Used only by EmbeddedCompositeFontFillTest.
 *
 * Object layout:
 *   obj 1  - Catalog (/AcroForm 9 0 R indirect ref)
 *   obj 2  - Pages root
 *   obj 3  - Page (one page, /Annots [ 4 0 R ])
 *   obj 4  - Field+widget dict (/FT /Tx /T (textfield) /DA (/F1 10 Tf))
 *   obj 5  - Type0 font dict (/Subtype /Type0 /Encoding /Identity-H)
 *   obj 6  - CIDFontType2 dict
 *   obj 7  - FontDescriptor (/FontFile2 8 0 R)
 *   obj 8  - FontFile2 stream (FreeSans.ttf bytes, /Filter /FlateDecode)
 *   obj 9  - ToUnicode CMap stream
 *   obj 10 - AcroForm dict (indirect, required by flattenFields())
 *
 * Path (a): drives CompositeFontEmitter::emit() with the full (non-subsetted)
 * TTF bytes so no subsetting step is required. The IndirectObjects returned by
 * emit() are serialized via IndirectObject::toBytes().
 */
final class CompositeEmbeddedFontFixtureBuilder
{
    private const string FONT_PATH = __DIR__ . '/assets/fonts/FreeSans.ttf';

    /**
     * Returns the bytes of a self-contained PDF with an embedded FreeSans Type0
     * field. PdfReader::fromBytes() must accept the result without error.
     */
    public static function build(): string
    {
        $ttfBytes = file_get_contents(self::FONT_PATH);
        if ($ttfBytes === false) {
            throw new \RuntimeException('Cannot read FreeSans.ttf from ' . self::FONT_PATH);
        }

        $parsed = TtfParser::parse($ttfBytes, 'FreeSans');

        // Use the full TTF as-is (no subsetting). The PostScript name carries no
        // subset-tag prefix so the BaseFont is simply /FreeSans in the dict tree.
        $subset = new SubsettedFont($ttfBytes, $parsed->postScriptName);

        // Object numbers:
        //   1 = Catalog, 2 = Pages, 3 = Page, 4 = Field
        //   5 = Type0, 6 = CIDFont, 7 = Descriptor, 8 = FontFile2, 9 = ToUnicode
        //   10 = AcroForm
        $type0Id = 5;
        $cidFontId = 6;
        $descriptorId = 7;
        $fontFileId = 8;
        $toUnicodeId = 9;

        $emitter = new CompositeFontEmitter();
        $emitted = $emitter->emit(
            $parsed,
            $subset,
            $type0Id,
            $cidFontId,
            $descriptorId,
            $fontFileId,
            $toUnicodeId,
        );

        // Serialize each emitted IndirectObject in order.
        $fontBytes = [];
        $fontBytes[$type0Id] = $emitted['type0']->toBytes();
        $fontBytes[$cidFontId] = $emitted['cidFont']->toBytes();
        $fontBytes[$descriptorId] = $emitted['descriptor']->toBytes();
        $fontBytes[$fontFileId] = $emitted['fontFile']->toBytes();
        $fontBytes[$toUnicodeId] = $emitted['toUnicode']->toBytes();

        // Assemble the PDF body incrementally, recording byte offsets.
        $body = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

        // obj 1: Catalog - /AcroForm points to obj 10 (indirect)
        $off1 = strlen($body);
        $body .= "1 0 obj\n";
        $body .= "<< /Type /Catalog /Pages 2 0 R /AcroForm 10 0 R >>\n";
        $body .= "endobj\n";

        // obj 2: Pages root
        $off2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>\nendobj\n";

        // obj 3: Page with widget annotation and empty /Resources
        $off3 = strlen($body);
        $body .= "3 0 obj\n";
        $body .= "<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 595 842 ]\n";
        $body .= "   /Resources << /Font << >> >>\n";
        $body .= "   /Annots [ 4 0 R ] >>\n";
        $body .= "endobj\n";

        // obj 4: Text field widget (combined field+widget)
        $off4 = strlen($body);
        $body .= "4 0 obj\n";
        $body .= "<< /Type /Annot /Subtype /Widget\n";
        $body .= "   /FT /Tx /T (textfield)\n";
        $body .= "   /DA (/F1 10 Tf)\n";
        $body .= "   /Rect [ 50 700 300 720 ]\n";
        $body .= "   /P 3 0 R >>\n";
        $body .= "endobj\n";

        // obj 5..9: Type0 font tree (serialized by CompositeFontEmitter)
        $off5 = strlen($body);
        $body .= $fontBytes[$type0Id];

        $off6 = strlen($body);
        $body .= $fontBytes[$cidFontId];

        $off7 = strlen($body);
        $body .= $fontBytes[$descriptorId];

        $off8 = strlen($body);
        $body .= $fontBytes[$fontFileId];

        $off9 = strlen($body);
        $body .= $fontBytes[$toUnicodeId];

        // obj 10: AcroForm (indirect, required by flattenFields())
        $off10 = strlen($body);
        $body .= "10 0 obj\n";
        $body .= "<< /Fields [ 4 0 R ]\n";
        $body .= "   /DA (/F1 10 Tf)\n";
        $body .= "   /DR << /Font << /F1 5 0 R >> >>\n";
        $body .= "   /NeedAppearances true >>\n";
        $body .= "endobj\n";

        // Cross-reference table (11 entries: free entry 0 + objs 1..10)
        $xrefOffset = strlen($body);
        $body .= "xref\n";
        $body .= "0 11\n";
        $body .= "0000000000 65535 f \n";
        $body .= self::xrefEntry($off1);
        $body .= self::xrefEntry($off2);
        $body .= self::xrefEntry($off3);
        $body .= self::xrefEntry($off4);
        $body .= self::xrefEntry($off5);
        $body .= self::xrefEntry($off6);
        $body .= self::xrefEntry($off7);
        $body .= self::xrefEntry($off8);
        $body .= self::xrefEntry($off9);
        $body .= self::xrefEntry($off10);

        $body .= "trailer\n<< /Size 11 /Root 1 0 R >>\n";
        $body .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $body;
    }

    private static function xrefEntry(int $offset): string
    {
        return str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
}
