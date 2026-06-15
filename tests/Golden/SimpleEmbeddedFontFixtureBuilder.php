<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;

/**
 * Builds a minimal source PDF whose /AcroForm /DR /Font /F1 is an embedded
 * TrueType font (FreeSans.ttf) and whose single text field /DA is "/F1 10 Tf".
 * Used only by EmbeddedSimpleFontFillTest.
 *
 * Object layout:
 *   obj 1  - Catalog (/AcroForm 8 0 R indirect ref)
 *   obj 2  - Pages root
 *   obj 3  - Page (one page, /Annots [ 4 0 R ])
 *   obj 4  - Field+widget dict (/FT /Tx /T (textfield) /DA (/F1 10 Tf))
 *   obj 5  - Font dictionary (/Type /Font /Subtype /TrueType /BaseFont /FreeSans
 *             /Encoding /WinAnsiEncoding /FirstChar 32 /LastChar 126 /Widths [...]
 *             /FontDescriptor 6 0 R)
 *   obj 6  - FontDescriptor (/FontDescriptor /FontName /FreeSans ... /FontFile2 7 0 R)
 *   obj 7  - FontFile2 stream (raw FreeSans.ttf bytes, /Filter /FlateDecode)
 *   obj 8  - AcroForm dict (indirect, required by flattenFields())
 *
 * The assembly mirrors the hand-built PDF pattern in
 * tests/Unit/Form/Fill/FieldValueApplierTextTest::testNonStandard14DrFontThrows().
 */
final class SimpleEmbeddedFontFixtureBuilder
{
    private const string FONT_PATH = __DIR__ . '/assets/fonts/FreeSans.ttf';

    /**
     * Returns the bytes of a self-contained PDF with an embedded FreeSans field.
     * PdfReader::fromBytes() must accept the result without error.
     */
    public static function build(): string
    {
        $ttfBytes = file_get_contents(self::FONT_PATH);
        if ($ttfBytes === false) {
            throw new \RuntimeException('Cannot read FreeSans.ttf from ' . self::FONT_PATH);
        }

        $parsed = TtfParser::parse($ttfBytes, 'FreeSans');

        // Build /Widths array for codes 32..126 (WinAnsi printable ASCII range).
        // WinAnsi code == unicode codepoint for codes 32-126.
        $firstChar = 32;
        $lastChar = 126;
        $widths = [];
        for ($code = $firstChar; $code <= $lastChar; $code++) {
            $unicode = $code; // ASCII range: byte code == unicode codepoint
            $gid = $parsed->cmap[$unicode] ?? 0;
            $advEm = $parsed->advanceWidthsByGid[$gid] ?? ($parsed->advanceWidthsByGid[0] ?? 0);
            $widths[] = (int) round($advEm * 1000 / $parsed->unitsPerEm);
        }

        // Produce the /Widths PDF array string.
        $widthsStr = '[ ' . implode(' ', $widths) . ' ]';

        // Build the /FontDescriptor fields from parsed metrics (scaled to 1000-em units).
        $upm = $parsed->unitsPerEm;
        $scale = static function (int $v) use ($upm): int {
            return (int) round($v * 1000 / $upm);
        };
        $ascent = $scale($parsed->ascent);
        $descent = $scale($parsed->descent);
        $capHeight = $scale($parsed->capHeight);
        [$bx0, $by0, $bx1, $by1] = $parsed->bbox;
        $bboxStr = '[' . $scale($bx0) . ' ' . $scale($by0) . ' ' . $scale($bx1) . ' ' . $scale($by1) . ']';

        // Derive /ItalicAngle and /StemV the same way the production composite
        // emitter does (AbstractCompositeFontEmitter::buildDescriptor): the post
        // table stores italicAngle as a 16.16 fixed-point value, and StemV is
        // estimated from the OS/2 weight class. For upright FreeSans this yields
        // ItalicAngle 0 / StemV 50, but mirroring production keeps the builder
        // correct for any font passed through it later.
        $italicAngle = $parsed->italicAngle >> 16;
        $stemV = 50 + (int) round((($parsed->weight - 400) ** 2) / 1000.0);

        // Compress the TTF bytes for embedding as /Filter /FlateDecode.
        $ttfUncompressedLen = strlen($ttfBytes);
        $compressed = gzcompress($ttfBytes, 6);
        if ($compressed === false) {
            throw new \RuntimeException('gzcompress failed on TTF bytes');
        }

        // Assemble the PDF body incrementally, recording byte offsets.
        $body = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; // header + binary comment

        // obj 1: Catalog - /AcroForm points to obj 8 (indirect, required by flattenFields)
        $off1 = strlen($body);
        $body .= "1 0 obj\n";
        $body .= "<< /Type /Catalog /Pages 2 0 R /AcroForm 8 0 R >>\n";
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

        // obj 5: Font dictionary (simple TrueType, WinAnsiEncoding)
        $off5 = strlen($body);
        $body .= "5 0 obj\n";
        $body .= "<< /Type /Font /Subtype /TrueType /BaseFont /FreeSans\n";
        $body .= "   /Encoding /WinAnsiEncoding\n";
        $body .= "   /FirstChar {$firstChar} /LastChar {$lastChar}\n";
        $body .= "   /Widths {$widthsStr}\n";
        $body .= "   /FontDescriptor 6 0 R >>\n";
        $body .= "endobj\n";

        // obj 6: FontDescriptor
        $off6 = strlen($body);
        $body .= "6 0 obj\n";
        $body .= "<< /Type /FontDescriptor /FontName /FreeSans\n";
        $body .= "   /Flags {$parsed->flags}\n";
        $body .= "   /FontBBox {$bboxStr}\n";
        $body .= "   /ItalicAngle {$italicAngle}\n";
        $body .= "   /Ascent {$ascent}\n";
        $body .= "   /Descent {$descent}\n";
        $body .= "   /CapHeight {$capHeight}\n";
        $body .= "   /StemV {$stemV}\n";
        $body .= "   /FontFile2 7 0 R >>\n";
        $body .= "endobj\n";

        // obj 7: FontFile2 stream (FreeSans.ttf, deflated)
        $compLen = strlen($compressed);
        $off7 = strlen($body);
        $body .= "7 0 obj\n";
        $body .= "<< /Length {$compLen} /Filter /FlateDecode /Length1 {$ttfUncompressedLen} >>\n";
        $body .= "stream\n";
        $body .= $compressed;
        $body .= "\nendstream\n";
        $body .= "endobj\n";

        // obj 8: AcroForm (indirect reference required by flattenFields())
        $off8 = strlen($body);
        $body .= "8 0 obj\n";
        $body .= "<< /Fields [ 4 0 R ]\n";
        $body .= "   /DA (/F1 10 Tf)\n";
        $body .= "   /DR << /Font << /F1 5 0 R >> >>\n";
        $body .= "   /NeedAppearances true >>\n";
        $body .= "endobj\n";

        // Cross-reference table
        $xrefOffset = strlen($body);
        $body .= "xref\n";
        $body .= "0 9\n";
        $body .= "0000000000 65535 f \n";
        $body .= self::xrefEntry($off1);
        $body .= self::xrefEntry($off2);
        $body .= self::xrefEntry($off3);
        $body .= self::xrefEntry($off4);
        $body .= self::xrefEntry($off5);
        $body .= self::xrefEntry($off6);
        $body .= self::xrefEntry($off7);
        $body .= self::xrefEntry($off8);

        $body .= "trailer\n<< /Size 9 /Root 1 0 R >>\n";
        $body .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $body;
    }

    private static function xrefEntry(int $offset): string
    {
        return str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
}
