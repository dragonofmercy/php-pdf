<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Writer\Object\Dictionary;
use PhpPdf\Writer\Object\Name;
use PhpPdf\Writer\Object\PdfNumber;
use PhpPdf\Writer\Object\PdfObject;

/**
 * Uncompressed XMP metadata stream with /Type /Metadata /Subtype /XML.
 * Kept separate from Stream so the extra dict entries are explicit.
 *
 * @internal
 */
final readonly class MetadataStream implements PdfObject
{
    public function __construct(private string $xmp) {}

    public function toBytes(): string
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Metadata'))
            ->withEntry(Name::of('Subtype'), Name::of('XML'))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(strlen($this->xmp)));
        return $dict->toBytes() . "\nstream\n" . $this->xmp . "\nendstream";
    }
}
