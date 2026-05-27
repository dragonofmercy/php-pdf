<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\ImageFormat;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\RawValue;

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
     * @param array<string, PdfReference> $fontRefs short name => font object reference
     * @return list<IndirectObject>
     */
    public function embed(Image $image, int $firstObjectNumber, ?FontRegistry $fontRegistry = null, array $fontRefs = []): array
    {
        return match ($image->format) {
            ImageFormat::JPEG => $this->embedJpeg($image, $firstObjectNumber),
            ImageFormat::PNG  => $this->embedPng($image, $firstObjectNumber),
            ImageFormat::SVG  => $this->embedSvg($image, $firstObjectNumber, $fontRegistry ?? new FontRegistry(), $fontRefs),
        };
    }

    public static function objectCount(Image $image): int
    {
        $meta = $image->metadata;
        if ($meta instanceof SvgMetadata) {
            $count = 1;
            foreach ($meta->embeddedImages as $child) {
                $count += self::objectCount($child);
            }
            return $count;
        }
        if ($meta instanceof PngMetadata && $meta->alphaBytes !== null) {
            return 2;
        }
        return 1;
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

        $dict = $this->xObjectBase($meta->width, $meta->height, 8, $colorSpace)
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

        return [
            IndirectObject::of($objectNumber, 0, new ImageStream($dict, $image->bytes)),
        ];
    }

    /**
     * @return list<IndirectObject>
     */
    private function embedPng(Image $image, int $objectNumber): array
    {
        $meta = $image->metadata;
        if (!$meta instanceof PngMetadata) {
            throw new PdfException('Embedder received non-PNG metadata for PNG format');
        }

        $alpha = $meta->alphaBytes;
        $imageObjectNumber = $objectNumber;
        $smaskRef = $alpha !== null ? PdfReference::to($objectNumber + 1, 0) : null;

        $dict = $this->pngImageDictionary($meta, $smaskRef);
        $body = $meta->colorBytes ?? $meta->idatBytes;

        $imageObject = IndirectObject::of(
            $imageObjectNumber,
            0,
            new ImageStream($dict, $body),
        );

        if ($alpha === null) {
            return [$imageObject];
        }

        $smaskDict = $this->xObjectBase($meta->width, $meta->height, $meta->bitDepth, Name::of('DeviceGray'))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'))
            ->withEntry(Name::of('DecodeParms'), $this->pngDecodeParms($meta->width, 1, $meta->bitDepth));

        return [
            $imageObject,
            IndirectObject::of($objectNumber + 1, 0, new ImageStream($smaskDict, $alpha)),
        ];
    }

    /**
     * @param array<string, PdfReference> $fontRefs
     * @return list<IndirectObject>
     */
    private function embedSvg(Image $image, int $objectNumber, FontRegistry $fontRegistry, array $fontRefs): array
    {
        $meta = $image->metadata;
        if (!$meta instanceof SvgMetadata) {
            throw new PdfException('Embedder received non-SVG metadata for SVG format');
        }

        $rendered = (new Renderer())->render($meta, $fontRegistry);
        $bytes = $rendered['bytes'];
        $extGStates = $rendered['extGStates'];
        $patterns = $rendered['patterns'];
        $fonts = $rendered['fonts'];

        $procSet = $fonts !== []
            ? PdfArray::of(Name::of('PDF'), Name::of('Text'))
            : PdfArray::of(Name::of('PDF'));
        $resources = Dictionary::empty()
            ->withEntry(Name::of('ProcSet'), $procSet);

        if ($fonts !== []) {
            $fontDict = Dictionary::empty();
            foreach ($fonts as $shortName) {
                if (!isset($fontRefs[$shortName])) {
                    throw new PdfException("SVG text references unregistered font '{$shortName}'");
                }
                $fontDict = $fontDict->withEntry(Name::of($shortName), $fontRefs[$shortName]);
            }
            $resources = $resources->withEntry(Name::of('Font'), $fontDict);
        }

        if ($extGStates !== []) {
            $extGStateDict = Dictionary::empty();
            foreach ($extGStates as $name => $entry) {
                $gsDict = Dictionary::empty()
                    ->withEntry(Name::of('ca'), PdfNumber::ofFloat($entry['ca']))
                    ->withEntry(Name::of('CA'), PdfNumber::ofFloat($entry['CA']));
                $extGStateDict = $extGStateDict->withEntry(Name::of($name), $gsDict);
            }
            $resources = $resources->withEntry(Name::of('ExtGState'), $extGStateDict);
        }

        if ($patterns !== []) {
            $patternDict = Dictionary::empty();
            foreach ($patterns as $name => $dict) {
                $patternDict = $patternDict->withEntry(Name::of($name), RawValue::of($dict));
            }
            $resources = $resources->withEntry(Name::of('Pattern'), $patternDict);
        }

        $childObjects = [];
        if ($meta->embeddedImages !== []) {
            $xobjectDict = Dictionary::empty();
            $childNum = $objectNumber + 1;
            foreach ($meta->embeddedImages as $i => $child) {
                $emitted = $this->embed($child, $childNum, $fontRegistry, $fontRefs);
                foreach ($emitted as $obj) {
                    $childObjects[] = $obj;
                }
                $xobjectDict = $xobjectDict->withEntry(Name::of('Im' . $i), PdfReference::to($childNum, 0));
                $childNum += count($emitted);
            }
            $resources = $resources->withEntry(Name::of('XObject'), $xobjectDict);
        }

        $extra = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Form'))
            ->withEntry(Name::of('FormType'), PdfNumber::ofInt(1))
            ->withEntry(Name::of('BBox'), PdfArray::of(
                PdfNumber::ofInt(0), PdfNumber::ofInt(0),
                PdfNumber::ofInt(1), PdfNumber::ofInt(1),
            ))
            ->withEntry(Name::of('Resources'), $resources);

        $stream = CompressedStream::of($bytes, $extra);

        return array_merge([IndirectObject::of($objectNumber, 0, $stream)], $childObjects);
    }

    private function pngImageDictionary(PngMetadata $meta, ?PdfReference $smaskRef): Dictionary
    {
        [$colorSpace, $colorChannels] = $this->pngColorSpace($meta);

        $dict = $this->xObjectBase($meta->width, $meta->height, $meta->bitDepth, $colorSpace)
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'))
            ->withEntry(
                Name::of('DecodeParms'),
                $this->pngDecodeParms($meta->width, $colorChannels, $meta->bitDepth),
            );

        if ($smaskRef !== null) {
            $dict = $dict->withEntry(Name::of('SMask'), $smaskRef);
        }

        return $dict;
    }

    private function xObjectBase(int $width, int $height, int $bitsPerComponent, PdfObject $colorSpace): Dictionary
    {
        return Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Image'))
            ->withEntry(Name::of('Width'), PdfNumber::ofInt($width))
            ->withEntry(Name::of('Height'), PdfNumber::ofInt($height))
            ->withEntry(Name::of('ColorSpace'), $colorSpace)
            ->withEntry(Name::of('BitsPerComponent'), PdfNumber::ofInt($bitsPerComponent));
    }

    private function pngDecodeParms(int $columns, int $colors, int $bitsPerComponent): Dictionary
    {
        return Dictionary::empty()
            ->withEntry(Name::of('Predictor'), PdfNumber::ofInt(15))
            ->withEntry(Name::of('Columns'), PdfNumber::ofInt($columns))
            ->withEntry(Name::of('Colors'), PdfNumber::ofInt($colors))
            ->withEntry(Name::of('BitsPerComponent'), PdfNumber::ofInt($bitsPerComponent));
    }

    /**
     * Returns [colorSpace PDF object, colorChannels for /Colors entry].
     *
     * @return array{PdfObject, int}
     */
    private function pngColorSpace(PngMetadata $meta): array
    {
        return match ($meta->colorType) {
            PngColorType::GRAY, PngColorType::GRAY_ALPHA => [Name::of('DeviceGray'), 1],
            PngColorType::RGB, PngColorType::RGB_ALPHA => [Name::of('DeviceRGB'), 3],
            PngColorType::PALETTE => [
                $this->indexedPalette($meta->palette ?? throw new PdfException('PNG palette missing')),
                1,
            ],
        };
    }

    private function indexedPalette(string $palette): PdfArray
    {
        $hival = (int) (strlen($palette) / 3) - 1;
        return PdfArray::of(
            Name::of('Indexed'),
            Name::of('DeviceRGB'),
            PdfNumber::ofInt($hival),
            HexString::of(bin2hex($palette)),
        );
    }
}
