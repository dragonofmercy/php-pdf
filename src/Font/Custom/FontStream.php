<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * PDF stream object carrying a custom dictionary plus a verbatim body.
 * /Length is appended at toBytes() time so the dict cannot drift from the
 * actual body size. Used for FontFile2 (TTF, /Length1 = raw size), FontFile3 (OpenType/CFF,
 * no /Length1), and ToUnicode CMap streams.
 *
 * @internal
 */
final readonly class FontStream implements PdfObject
{
    public function __construct(
        private Dictionary $dictionary,
        private string $body,
    ) {}

    /** @internal */
    public function dictionary(): Dictionary
    {
        return $this->dictionary;
    }

    /** @internal */
    public function body(): string
    {
        return $this->body;
    }

    public function toBytes(): string
    {
        $dict = $this->dictionary->withEntry(
            Name::of('Length'),
            PdfNumber::ofInt(strlen($this->body)),
        );
        return $dict->toBytes() . "\nstream\n" . $this->body . "\nendstream";
    }
}
