<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\ImageFormat;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;

/**
 * Produces the IndirectObjects for a single image.
 *
 * - JPEG: 1 object, /Filter /DCTDecode, raw bytes verbatim.
 * - Opaque PNG: 1 object, /Filter /FlateDecode + /DecodeParms /Predictor 15.
 * - PNG with alpha: 2 objects (image + SMask). The image XObject references
 *   the SMask via /SMask <smask-ref>.
 *
 * @internal
 */
final class ImageEmbedder
{
    /**
     * @return list<IndirectObject>
     */
    public function embed(Image $image, int $firstObjectNumber): array
    {
        return match ($image->format) {
            ImageFormat::JPEG => $this->embedJpeg($image, $firstObjectNumber),
            ImageFormat::PNG => $this->embedPng($image, $firstObjectNumber),
        };
    }

    /**
     * @return list<IndirectObject>
     */
    private function embedJpeg(Image $image, int $objectNumber): array
    {
        $meta = $image->metadata;
        if (!$meta instanceof JpegMetadata) {
            throw new PdfException('Embedder received non-JPEG metadata for JPEG format');
        }

        $colorSpace = match ($meta->components) {
            1 => Name::of('DeviceGray'),
            3 => Name::of('DeviceRGB'),
            4 => Name::of('DeviceCMYK'),
            default => throw new PdfException("Cannot embed JPEG with {$meta->components} components"),
        };

        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Image'))
            ->withEntry(Name::of('Width'), PdfNumber::ofInt($meta->width))
            ->withEntry(Name::of('Height'), PdfNumber::ofInt($meta->height))
            ->withEntry(Name::of('ColorSpace'), $colorSpace)
            ->withEntry(Name::of('BitsPerComponent'), PdfNumber::ofInt(8))
            ->withEntry(Name::of('Filter'), Name::of('DCTDecode'));

        if ($meta->components === 4) {
            $dict = $dict->withEntry(
                Name::of('Decode'),
                PdfArray::of(
                    PdfNumber::ofInt(1), PdfNumber::ofInt(0),
                    PdfNumber::ofInt(1), PdfNumber::ofInt(0),
                    PdfNumber::ofInt(1), PdfNumber::ofInt(0),
                    PdfNumber::ofInt(1), PdfNumber::ofInt(0),
                ),
            );
        }

        $dict = $dict->withEntry(Name::of('Length'), PdfNumber::ofInt(strlen($image->bytes)));

        return [
            IndirectObject::of($objectNumber, 0, new ImageStream($dict, $image->bytes)),
        ];
    }

    /**
     * @return list<IndirectObject>
     */
    private function embedPng(Image $image, int $objectNumber): array
    {
        // Filled in Tasks 13 and 14.
        throw new PdfException('PNG embedding not yet implemented');
    }
}
