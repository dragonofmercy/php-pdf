<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * PDF stream object that holds raw image bytes plus an XObject dictionary.
 * Unlike CompressedStream, the body is written verbatim -- image data is
 * already in its target PDF filter format (JPEG = DCTDecode-decodable;
 * PNG = FlateDecode-compressed with PNG predictor).
 *
 * @internal
 */
final readonly class ImageStream implements PdfObject
{
    public function __construct(
        private Dictionary $dictionary,
        private string $body,
    ) {}

    public function toBytes(): string
    {
        $dict = $this->dictionary->toBytes();
        return $dict . "\nstream\n" . $this->body . "\nendstream";
    }
}
