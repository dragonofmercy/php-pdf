<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\ImageFormat;
use DragonOfMercy\PhpPdf\Svg\EmbeddedMask;
use DragonOfMercy\PhpPdf\Svg\EmbeddedPattern;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgPattern;
use DragonOfMercy\PhpPdf\Svg\SvgShape;
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
            // Tiling patterns and masks are allocated at render time (the /Matrix
            // depends on the painted shape's CTM and bbox). Detect usage cheaply,
            // then pre-render to get the exact count.
            if (self::svgHasPatternPaint($meta) || self::svgHasMaskPaint($meta)) {
                $rendered = (new Renderer())->render($meta);
                $count += count($rendered['embeddedPatterns']);
                $count += count($rendered['embeddedMasks']);
            }
            return $count;
        }
        if ($meta instanceof PngMetadata && $meta->alphaBytes !== null) {
            return 2;
        }
        return 1;
    }

    private static function svgHasPatternPaint(SvgMetadata $meta): bool
    {
        return self::nodeHasPatternPaint($meta->root);
    }

    private static function nodeHasPatternPaint(SvgNode $node): bool
    {
        if ($node instanceof SvgGroup) {
            foreach ($node->children as $c) {
                if (self::nodeHasPatternPaint($c)) {
                    return true;
                }
            }
            return false;
        }
        if ($node instanceof SvgClipped) {
            return self::nodeHasPatternPaint($node->child);
        }
        if ($node instanceof SvgMasked) {
            return self::nodeHasPatternPaint($node->child);
        }
        if ($node instanceof SvgShape) {
            $paint = $node->paint();
            return $paint->fill instanceof SvgPattern
                || $paint->stroke instanceof SvgPattern;
        }
        return false;
    }

    private static function svgHasMaskPaint(SvgMetadata $meta): bool
    {
        return self::nodeHasMaskPaint($meta->root);
    }

    private static function nodeHasMaskPaint(SvgNode $node): bool
    {
        if ($node instanceof SvgMasked) {
            return true;
        }
        if ($node instanceof SvgGroup) {
            foreach ($node->children as $c) {
                if (self::nodeHasMaskPaint($c)) {
                    return true;
                }
            }
            return false;
        }
        if ($node instanceof SvgClipped) {
            return self::nodeHasMaskPaint($node->child);
        }
        return false;
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
        $patternRefs = $rendered['patternRefs'];
        $embeddedPatterns = $rendered['embeddedPatterns'];
        $embeddedMasks = $rendered['embeddedMasks'];
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

        // (1) Embedded images loop: allocate child Form/image objects for raster
        // <image> children. /XObject dict is staged here but only attached to
        // /Resources later to preserve the original entry order
        // (ExtGState before XObject).
        $childObjects = [];
        $xobjectDict = null;
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
        } else {
            $childNum = $objectNumber + 1;
        }

        // (2) Tiling patterns become child indirect objects allocated next.
        $patternChildNumbers = []; // embeddedIndex -> child object number
        foreach ($patternRefs as $refEntry) {
            $emb = $embeddedPatterns[$refEntry['embeddedIndex']];
            $childObjects[] = $this->buildTilingPatternObject($childNum, $emb);
            $patternChildNumbers[$refEntry['embeddedIndex']] = $childNum;
            $childNum++;
        }

        // (3) Soft-mask Form XObjects: one indirect object per EmbeddedMask.
        $maskChildNumbers = []; // embeddedMaskIndex -> child object number
        foreach ($embeddedMasks as $maskIndex => $maskEmb) {
            $childObjects[] = $this->buildMaskFormObject($childNum, $maskEmb);
            $maskChildNumbers[$maskIndex] = $childNum;
            $childNum++;
        }

        // (4) Build /Resources/ExtGState including /SMask entries. Added before
        // /XObject and /Pattern to keep the historical entry order.
        if ($extGStates !== []) {
            $extGStateDict = Dictionary::empty();
            foreach ($extGStates as $name => $entry) {
                $gsDict = Dictionary::empty()
                    ->withEntry(Name::of('ca'), PdfNumber::ofFloat($entry['ca']))
                    ->withEntry(Name::of('CA'), PdfNumber::ofFloat($entry['CA']));
                if ($entry['smaskEmbeddedIndex'] !== null) {
                    $maskFormRef = PdfReference::to($maskChildNumbers[$entry['smaskEmbeddedIndex']], 0);
                    $smaskDict = Dictionary::empty()
                        ->withEntry(Name::of('Type'), Name::of('Mask'))
                        ->withEntry(Name::of('S'), Name::of('Luminosity'))
                        ->withEntry(Name::of('G'), $maskFormRef);
                    $gsDict = $gsDict->withEntry(Name::of('SMask'), $smaskDict);
                }
                $extGStateDict = $extGStateDict->withEntry(Name::of($name), $gsDict);
            }
            $resources = $resources->withEntry(Name::of('ExtGState'), $extGStateDict);
        }

        // (5) Attach the staged /XObject dict now (after /ExtGState).
        if ($xobjectDict !== null) {
            $resources = $resources->withEntry(Name::of('XObject'), $xobjectDict);
        }

        // (6) Build /Resources/Pattern combining inline shading-pattern dicts AND tiling-pattern refs.
        if ($patterns !== [] || $patternRefs !== []) {
            $patternDict = Dictionary::empty();
            foreach ($patterns as $name => $dict) {
                $patternDict = $patternDict->withEntry(Name::of($name), RawValue::of($dict));
            }
            foreach ($patternRefs as $refEntry) {
                $childNumForThis = $patternChildNumbers[$refEntry['embeddedIndex']];
                $patternDict = $patternDict->withEntry(Name::of($refEntry['name']), PdfReference::to($childNumForThis, 0));
            }
            $resources = $resources->withEntry(Name::of('Pattern'), $patternDict);
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

    private function buildMaskFormObject(int $childNum, EmbeddedMask $emb): IndirectObject
    {
        $resources = Dictionary::empty()
            ->withEntry(Name::of('ProcSet'), PdfArray::of(Name::of('PDF')));
        if ($emb->extGStates !== []) {
            $extGStateDict = Dictionary::empty();
            foreach ($emb->extGStates as $gsName => $entry) {
                $gsDict = Dictionary::empty()
                    ->withEntry(Name::of('ca'), PdfNumber::ofFloat($entry['ca']))
                    ->withEntry(Name::of('CA'), PdfNumber::ofFloat($entry['CA']));
                // Nested smask refs inside a mask are out of scope.
                $extGStateDict = $extGStateDict->withEntry(Name::of($gsName), $gsDict);
            }
            $resources = $resources->withEntry(Name::of('ExtGState'), $extGStateDict);
        }
        $groupDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Group'))
            ->withEntry(Name::of('S'), Name::of('Transparency'))
            ->withEntry(Name::of('CS'), Name::of('DeviceRGB'));
        $extras = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Form'))
            ->withEntry(Name::of('FormType'), PdfNumber::ofInt(1))
            ->withEntry(Name::of('BBox'), PdfArray::of(
                PdfNumber::ofFloat($emb->bbox[0]), PdfNumber::ofFloat($emb->bbox[1]),
                PdfNumber::ofFloat($emb->bbox[2]), PdfNumber::ofFloat($emb->bbox[3]),
            ))
            ->withEntry(Name::of('Matrix'), PdfArray::of(
                PdfNumber::ofFloat($emb->matrix[0]), PdfNumber::ofFloat($emb->matrix[1]),
                PdfNumber::ofFloat($emb->matrix[2]), PdfNumber::ofFloat($emb->matrix[3]),
                PdfNumber::ofFloat($emb->matrix[4]), PdfNumber::ofFloat($emb->matrix[5]),
            ))
            ->withEntry(Name::of('Group'), $groupDict)
            ->withEntry(Name::of('Resources'), $resources);
        return IndirectObject::of($childNum, 0, CompressedStream::of($emb->contentBytes, $extras));
    }

    private function buildTilingPatternObject(int $childNum, EmbeddedPattern $emb): IndirectObject
    {
        $tileResources = Dictionary::empty()
            ->withEntry(Name::of('ProcSet'), PdfArray::of(Name::of('PDF')));
        if ($emb->extGStates !== []) {
            $extGStateDict = Dictionary::empty();
            foreach ($emb->extGStates as $gsName => $entry) {
                $gsDict = Dictionary::empty()
                    ->withEntry(Name::of('ca'), PdfNumber::ofFloat($entry['ca']))
                    ->withEntry(Name::of('CA'), PdfNumber::ofFloat($entry['CA']));
                $extGStateDict = $extGStateDict->withEntry(Name::of($gsName), $gsDict);
            }
            $tileResources = $tileResources->withEntry(Name::of('ExtGState'), $extGStateDict);
        }
        $extras = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Pattern'))
            ->withEntry(Name::of('PatternType'), PdfNumber::ofInt(1))
            ->withEntry(Name::of('PaintType'), PdfNumber::ofInt(1))
            ->withEntry(Name::of('TilingType'), PdfNumber::ofInt(1))
            ->withEntry(Name::of('BBox'), PdfArray::of(
                PdfNumber::ofFloat($emb->bbox[0]), PdfNumber::ofFloat($emb->bbox[1]),
                PdfNumber::ofFloat($emb->bbox[2]), PdfNumber::ofFloat($emb->bbox[3]),
            ))
            ->withEntry(Name::of('XStep'), PdfNumber::ofFloat($emb->xStep))
            ->withEntry(Name::of('YStep'), PdfNumber::ofFloat($emb->yStep))
            ->withEntry(Name::of('Matrix'), PdfArray::of(
                PdfNumber::ofFloat($emb->matrix[0]), PdfNumber::ofFloat($emb->matrix[1]),
                PdfNumber::ofFloat($emb->matrix[2]), PdfNumber::ofFloat($emb->matrix[3]),
                PdfNumber::ofFloat($emb->matrix[4]), PdfNumber::ofFloat($emb->matrix[5]),
            ))
            ->withEntry(Name::of('Resources'), $tileResources);
        return IndirectObject::of($childNum, 0, CompressedStream::of($emb->contentBytes, $extras));
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
