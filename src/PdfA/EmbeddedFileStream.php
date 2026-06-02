<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * An /EmbeddedFile stream: the given dictionary plus /Length and the raw file
 * bytes (uncompressed, so /Params /Size and /CheckSum match the bytes).
 *
 * @internal
 */
final readonly class EmbeddedFileStream implements PdfObject
{
    public function __construct(private Dictionary $dict, private string $bytes) {}

    public function toBytes(): string
    {
        $dict = $this->dict->withEntry(Name::of('Length'), PdfNumber::ofInt(strlen($this->bytes)));
        return $dict->toBytes() . "\nstream\n" . $this->bytes . "\nendstream";
    }
}
