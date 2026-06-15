<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill\Font;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\ParsedTtf;
use DragonOfMercy\PhpPdf\Font\Custom\TtfParser;
use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * Parses a PDF Type0 (Identity-H / CIDFontType2 / FontFile2) font dictionary
 * into a ParsedTtf for use in AcroForm appearance generation. Performs eager
 * validation and throws PdfException naming the field on any unsupported
 * configuration.
 *
 * Supported configuration:
 * - /Encoding must be /Identity-H (no other CMaps supported)
 * - /DescendantFonts first element must be a CIDFontType2 (CFF CIDFontType0 not supported)
 * - /CIDToGIDMap must be absent or the name /Identity (stream remapping not supported)
 * - /FontDescriptor /FontFile2 must be present and parseable as a TrueType program
 *
 * @internal
 */
final class CompositeFontDictReader
{
    public static function read(Dictionary $type0Dict, PdfReader $reader, string $fieldName): ParsedTtf
    {
        $resolve = fn (PdfObject $o): PdfObject => $reader->resolve($o);

        // /Encoding must be Identity-H
        $encoding = DictReader::name($type0Dict, 'Encoding', $resolve);
        if ($encoding !== 'Identity-H') {
            throw new PdfException(sprintf(
                "Field '%s': Type0 font /Encoding must be Identity-H, got \"%s\"",
                $fieldName,
                $encoding ?? '(missing)',
            ));
        }

        // /DescendantFonts must be a non-empty array; resolve first element
        $descendantsEntry = $type0Dict->get(Name::of('DescendantFonts'));
        if ($descendantsEntry === null) {
            throw new PdfException(sprintf("Field '%s': Type0 font is missing /DescendantFonts", $fieldName));
        }
        $descendants = $resolve($descendantsEntry);
        if (!$descendants instanceof PdfArray || $descendants->elements() === []) {
            throw new PdfException(sprintf("Field '%s': Type0 font /DescendantFonts must be a non-empty array", $fieldName));
        }
        $cidFontObj = $resolve($descendants->elements()[0]);
        if (!$cidFontObj instanceof Dictionary) {
            throw new PdfException(sprintf("Field '%s': Type0 font /DescendantFonts[0] must be a font dictionary", $fieldName));
        }

        // /Subtype of the CIDFont must be CIDFontType2 (TrueType-based)
        $cidSubtype = DictReader::name($cidFontObj, 'Subtype', $resolve);
        if ($cidSubtype !== 'CIDFontType2') {
            throw new PdfException(sprintf(
                "Field '%s': CIDFont /Subtype must be CIDFontType2 (CFF CIDFontType0 not supported), got \"%s\"",
                $fieldName,
                $cidSubtype ?? '(missing)',
            ));
        }

        // /CIDToGIDMap: if present must be the name Identity (stream remapping not supported)
        $cidToGidEntry = $cidFontObj->get(Name::of('CIDToGIDMap'));
        if ($cidToGidEntry !== null) {
            $cidToGid = $resolve($cidToGidEntry);
            if (!$cidToGid instanceof Name || $cidToGid->value() !== 'Identity') {
                throw new PdfException(sprintf(
                    "Field '%s': Type0 font /CIDToGIDMap must be absent or the name Identity (stream remapping not supported)",
                    $fieldName,
                ));
            }
        }

        // /FontDescriptor is required; /FontFile2 must be a readable stream
        $descriptor = DictReader::dictionary($cidFontObj, 'FontDescriptor', $resolve);
        if ($descriptor === null) {
            throw new PdfException(sprintf("Field '%s': CIDFont is missing /FontDescriptor", $fieldName));
        }

        $fontFile2Entry = $descriptor->get(Name::of('FontFile2'));
        if ($fontFile2Entry === null) {
            throw new PdfException(sprintf(
                "Field '%s': CIDFont /FontDescriptor has no /FontFile2 (/FontFile3 CFF is not supported)",
                $fieldName,
            ));
        }
        $fontFile2 = $resolve($fontFile2Entry);
        if (!$fontFile2 instanceof ReadStream) {
            throw new PdfException(sprintf("Field '%s': /FontFile2 must be a stream object", $fieldName));
        }

        $fontBytes = $reader->decodeStream($fontFile2);

        try {
            return TtfParser::parse($fontBytes, "field '{$fieldName}'");
        } catch (PdfException $e) {
            throw new PdfException(sprintf("Field '%s': embedded font program could not be parsed: %s", $fieldName, $e->getMessage()), 0, $e);
        }
    }
}
