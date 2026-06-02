<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * FlateDecode ICC profile stream for an sRGB (3-component) output intent.
 *
 * @internal
 */
final readonly class IccProfileStream implements PdfObject
{
    public function __construct(private string $compressed) {}

    public function toBytes(): string
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('N'), PdfNumber::ofInt(3))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'))
            ->withEntry(Name::of('Length'), PdfNumber::ofInt(strlen($this->compressed)));
        return $dict->toBytes() . "\nstream\n" . $this->compressed . "\nendstream";
    }
}
