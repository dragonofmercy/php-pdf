<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for filling and flattening a text field whose /DA references
 * an embedded Type0/CIDFontType2/Identity-H font (FreeSans) in /AcroForm /DR /Font.
 *
 * The source PDF is built in-memory by CompositeEmbeddedFontFixtureBuilder; it is
 * NOT committed as a stored fixture because it contains the full FreeSans.ttf bytes.
 */
final class EmbeddedCompositeFontFillTest extends TestCase
{
    private const string FONT_PATH = __DIR__ . '/assets/fonts/FreeSans.ttf';

    // -------------------------------------------------------------------------
    // Test 1: filling the field generates a correct hex /AP appearance stream
    // -------------------------------------------------------------------------

    public function testFillType0FieldGeneratesHexAppearance(): void
    {
        $src = CompositeEmbeddedFontFixtureBuilder::build();

        // Must not throw "not a Standard-14 font" or any other error.
        $filled = PdfEditor::fromBytes($src)->setField('textfield', 'Hi')->output();

        $reader = PdfReader::fromBytes($filled);

        // Walk /AcroForm /Fields to find the widget and its /AP /N stream.
        $catalog = $reader->catalog();
        $acroFormRaw = $catalog->get(Name::of('AcroForm'));
        self::assertNotNull($acroFormRaw, '/AcroForm must exist in filled PDF catalog');

        $acroForm = $reader->resolve($acroFormRaw);
        self::assertInstanceOf(Dictionary::class, $acroForm);

        $fieldsRaw = $acroForm->get(Name::of('Fields'));
        self::assertNotNull($fieldsRaw, '/AcroForm must have /Fields');
        $fields = $reader->resolve($fieldsRaw);
        self::assertInstanceOf(PdfArray::class, $fields, '/AcroForm /Fields must resolve to an array');

        // Collect the appearance stream content from the first widget that has /AP /N.
        $apContent = null;
        foreach ($fields->elements() as $fieldRef) {
            $field = $reader->resolve($fieldRef);
            if (!$field instanceof Dictionary) {
                continue;
            }
            $apRaw = $field->get(Name::of('AP'));
            if ($apRaw === null) {
                continue;
            }
            $ap = $reader->resolve($apRaw);
            if (!$ap instanceof Dictionary) {
                continue;
            }
            $nRaw = $ap->get(Name::of('N'));
            if ($nRaw === null) {
                continue;
            }
            $n = $reader->resolve($nRaw);
            if (!$n instanceof ReadStream) {
                continue;
            }
            $decoded = $reader->decodeStream($n);
            $apContent = $decoded;
            break;
        }

        self::assertNotNull($apContent, 'Filled field must have an /AP /N appearance stream');

        // The appearance stream must select the embedded Type0 font.
        self::assertStringContainsString('/F1 10 Tf', $apContent, 'Appearance must contain /F1 10 Tf operator');

        // For Identity-H, each character is a 2-byte GID encoded as hex.
        // Compute the expected hex operand from the parsed FreeSans cmap.
        $ttfBytes = file_get_contents(self::FONT_PATH);
        self::assertIsString($ttfBytes);
        $parsed = TtfParser::parse($ttfBytes, 'FreeSans');

        // 'H' = U+0048, 'i' = U+0069
        $gidH = $parsed->cmap[0x0048] ?? 0;
        $gidI = $parsed->cmap[0x0069] ?? 0;

        // Each GID is 2 bytes big-endian.
        $hexH = strtoupper(str_pad(dechex($gidH), 4, '0', STR_PAD_LEFT));
        $hexI = strtoupper(str_pad(dechex($gidI), 4, '0', STR_PAD_LEFT));
        $expectedHex = '<' . $hexH . $hexI . '>';

        self::assertStringContainsString(
            $expectedHex . ' Tj',
            $apContent,
            "Appearance must contain {$expectedHex} Tj (2-byte Identity-H encoding of 'Hi')",
        );
    }

    // -------------------------------------------------------------------------
    // Test 2: flattening removes /AcroForm and burns the XObject in
    // -------------------------------------------------------------------------

    public function testFlattenType0Field(): void
    {
        $src = CompositeEmbeddedFontFixtureBuilder::build();

        $flattened = PdfEditor::fromBytes($src)
            ->setField('textfield', 'Hi')
            ->flattenFields()
            ->output();

        $reader = PdfReader::fromBytes($flattened);

        // /AcroForm must be absent from the catalog after flattening.
        $acroFormEntry = $reader->catalog()->get(Name::of('AcroForm'));
        self::assertNull($acroFormEntry, '/AcroForm must be removed from the catalog after flattening');

        // The page content stream(s) must reference the burned-in appearance XObject.
        // flattenFields() appends a burn content stream that invokes the XObject via Do.
        $page = $reader->page(1);
        $contentRaw = $page->dict->get(Name::of('Contents'));
        self::assertNotNull($contentRaw, 'Page must have a /Contents entry after flattening');

        // Collect all content streams - /Contents may be a single reference or an array.
        $pageContent = '';
        $resolved = $reader->resolve($contentRaw);
        if ($resolved instanceof ReadStream) {
            $pageContent = $reader->decodeStream($resolved);
        } elseif ($resolved instanceof PdfArray) {
            foreach ($resolved->elements() as $el) {
                $stream = $reader->resolve($el);
                if ($stream instanceof ReadStream) {
                    $pageContent .= $reader->decodeStream($stream);
                }
            }
        }

        self::assertStringContainsString(' Do', $pageContent, 'Flattened page content must invoke an XObject via Do');
    }

    // -------------------------------------------------------------------------
    // Test 3: qpdf --check accepts the filled output (auto-skipped without qpdf)
    // -------------------------------------------------------------------------

    public function testQpdfCheckFilledOutput(): void
    {
        $src = CompositeEmbeddedFontFixtureBuilder::build();
        $filled = PdfEditor::fromBytes($src)->setField('textfield', 'Hi')->output();

        $tmp = tempnam(sys_get_temp_dir(), 'phppdf_compfont_');
        self::assertIsString($tmp);
        try {
            file_put_contents($tmp, $filled);
            Qpdf::assertCheck($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
