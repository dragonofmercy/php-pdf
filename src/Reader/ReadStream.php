<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * A stream object parsed from an existing PDF: its dictionary plus the RAW
 * (still encoded) payload bytes. toBytes() re-emits the stream with /Length
 * corrected to the actual payload size, so re-serialization in a new or
 * incremental revision is lossless.
 *
 * @internal
 */
final readonly class ReadStream implements PdfObject
{
    public function __construct(
        public Dictionary $dict,
        private string $rawData,
    ) {}

    public function rawData(): string
    {
        return $this->rawData;
    }

    public function toBytes(): string
    {
        $dict = $this->dict->withEntry(Name::of('Length'), PdfNumber::ofInt(strlen($this->rawData)));
        return $dict->toBytes() . "\nstream\n" . $this->rawData . "\nendstream";
    }
}
