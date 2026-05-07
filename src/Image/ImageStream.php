<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * PDF stream object that holds raw image bytes plus an XObject dictionary.
 * Unlike CompressedStream, the body is written verbatim -- image data is
 * already in its target PDF filter format (JPEG = DCTDecode-decodable;
 * PNG = FlateDecode-compressed with PNG predictor).
 *
 * The /Length entry is appended automatically at toBytes() time so callers
 * (and ObjectTransformer when re-emitting after encryption) cannot drift
 * from the actual body size.
 *
 * @internal
 */
final readonly class ImageStream implements PdfObject
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
