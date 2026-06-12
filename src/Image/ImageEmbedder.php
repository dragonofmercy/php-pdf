<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\FontResolver;
use DragonOfMercy\PhpPdf\Font\FontRegistry;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\ImageFormat;
use DragonOfMercy\PhpPdf\Svg\EmbeddedMask;
use DragonOfMercy\PhpPdf\Svg\EmbeddedPattern;
use DragonOfMercy\PhpPdf\Svg\Renderer;
use DragonOfMercy\PhpPdf\Svg\EmbeddedFilter;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgFiltered;
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
     * @param ?FontResolver $fontResolver custom font resolver for SVG text rendering;
     *        also supplies the SVG font-family alias map
     * @param int $filterDpi raster resolution (DPI) used when rasterizing SVG filter subtrees
     * @param bool $forbidsTransparency when true (PDF/A-1), throws instead of emitting any
     *        transparency (PNG alpha SMask, SVG fill/stroke opacity, SVG mask/soft-mask)
     * @return list<IndirectObject>
     */
    public function embed(
        Image $image,
        int $firstObjectNumber,
        ?FontRegistry $fontRegistry = null,
        array $fontRefs = [],
        ?FontResolver $fontResolver = null,
        int $filterDpi = 300,
        bool $forbidsTransparency = false,
    ): array {
        return match ($image->format) {
            ImageFormat::JPEG => $this->embedJpeg($image, $firstObjectNumber),
            ImageFormat::PNG  => $this->embedPng($image, $firstObjectNumber, $forbidsTransparency),
            ImageFormat::SVG  => $this->embedSvg($image, $firstObjectNumber, $fontRegistry ?? new FontRegistry(), $fontRefs, $fontResolver, $filterDpi, $forbidsTransparency),
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
            // Tiling patterns, masks, and filter rasters are allocated at render
            // time (their object count depends on render-time decisions such as
            // /Matrix, bbox, and whether a filtered region is degenerate). Detect
            // usage cheaply, then pre-render to get the exact count.
            if (self::svgHasPatternPaint($meta) || self::svgHasMaskPaint($meta) || self::svgHasFilterPaint($meta)) {
                // Font context is intentionally omitted: it does not affect the pattern/mask/filter count.
                // This pre-render must produce the same embeddedPatterns/embeddedMasks/embeddedFilters counts as embedSvg() for object numbering to stay correct.
                $rendered = (new Renderer())->render($meta);
                $count += count($rendered['embeddedPatterns']);
                $count += count($rendered['embeddedMasks']);
                // Each filter raster is a color image XObject plus a DeviceGray SMask.
                $count += count($rendered['embeddedFilters']) * 2;
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

    private static function svgHasFilterPaint(SvgMetadata $meta): bool
    {
        return self::nodeHasFilterPaint($meta->root);
    }

    private static function nodeHasFilterPaint(SvgNode $node): bool
    {
        if ($node instanceof SvgFiltered) {
            return true;
        }
        if ($node instanceof SvgGroup) {
            foreach ($node->children as $c) {
                if (self::nodeHasFilterPaint($c)) {
                    return true;
                }
            }
            return false;
        }
        if ($node instanceof SvgClipped) {
            return self::nodeHasFilterPaint($node->child);
        }
        if ($node instanceof SvgMasked) {
            return self::nodeHasFilterPaint($node->child);
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
    private function embedPng(Image $image, int $objectNumber, bool $forbidsTransparency = false): array
    {
        $meta = $image->metadata;
        if (!$meta instanceof PngMetadata) {
            throw new PdfException('Embedder received non-PNG metadata for PNG format');
        }

        $alpha = $meta->alphaBytes;
        if ($forbidsTransparency && $alpha !== null) {
            throw new PdfException(sprintf(
                'PDF/A-1 forbids transparency; PNG image %dx%d has an alpha channel - flatten it against a solid background before adding it',
                $meta->width,
                $meta->height,
            ));
        }
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
    private function embedSvg(Image $image, int $objectNumber, FontRegistry $fontRegistry, array $fontRefs, ?FontResolver $fontResolver = null, int $filterDpi = 300, bool $forbidsTransparency = false): array
    {
        $meta = $image->metadata;
        if (!$meta instanceof SvgMetadata) {
            throw new PdfException('Embedder received non-SVG metadata for SVG format');
        }

        $rendered = (new Renderer($filterDpi))->render($meta, $fontRegistry, $fontResolver);
        $bytes = $rendered['bytes'];
        $extGStates = $rendered['extGStates'];
        $patterns = $rendered['patterns'];
        $patternRefs = $rendered['patternRefs'];
        $embeddedPatterns = $rendered['embeddedPatterns'];
        $embeddedMasks = $rendered['embeddedMasks'];
        $embeddedFilters = $rendered['embeddedFilters'];
        $fonts = $rendered['fonts'];

        if ($forbidsTransparency) {
            // A mask/soft-mask (and a rasterized filter, which emits an SMask
            // image) has no PDF 1.4 representation; reject before emitting it.
            if ($embeddedMasks !== [] || $embeddedFilters !== []) {
                throw new PdfException(
                    'PDF/A-1 forbids transparency; the SVG uses a mask, soft-mask, or filter, which has no PDF 1.4 representation - remove it or use PDF/A-2 or higher',
                );
            }
            // Any ExtGState entry registered by the renderer means a fill/stroke
            // opacity below 1.0 (fully opaque maskless entries are never registered).
            if ($extGStates !== []) {
                throw new PdfException(
                    'PDF/A-1 forbids transparency; the SVG uses fill/stroke opacity below 1.0, which has no PDF 1.4 representation - remove the opacity or use PDF/A-2 or higher',
                );
            }
        }

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
        $childNum = $objectNumber + 1;
        if ($meta->embeddedImages !== [] || $embeddedFilters !== []) {
            $xobjectDict = Dictionary::empty();
            foreach ($meta->embeddedImages as $i => $child) {
                $emitted = $this->embed($child, $childNum, $fontRegistry, $fontRefs, $fontResolver, forbidsTransparency: $forbidsTransparency);
                foreach ($emitted as $obj) {
                    $childObjects[] = $obj;
                }
                $xobjectDict = $xobjectDict->withEntry(Name::of('Im' . $i), PdfReference::to($childNum, 0));
                $childNum += count($emitted);
            }
            // Filter rasters: a DeviceRGB color image + its DeviceGray SMask,
            // mirroring the alpha-PNG construction. Named /ImF{index} to match
            // the renderer's content-stream reference.
            foreach ($embeddedFilters as $i => $filter) {
                $smaskNum = $childNum + 1;
                [$colorObject, $smaskObject] = $this->buildFilterObjects($childNum, $smaskNum, $filter);
                $childObjects[] = $colorObject;
                $childObjects[] = $smaskObject;
                $xobjectDict = $xobjectDict->withEntry(Name::of('ImF' . $i), PdfReference::to($childNum, 0));
                $childNum += 2;
            }
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

    /**
     * Builds a /Resources dict with /ProcSet [/PDF] and (when non-empty) a
     * /ExtGState sub-dict of ca/CA-only entries. Used by tiling-pattern and
     * soft-mask Form XObjects, whose inner ExtGState entries never carry /SMask.
     *
     * @param array<string, array{ca: float, CA: float, smaskEmbeddedIndex: ?int}> $extGStates
     */
    private function resourcesWithExtGStates(array $extGStates): Dictionary
    {
        $resources = Dictionary::empty()
            ->withEntry(Name::of('ProcSet'), PdfArray::of(Name::of('PDF')));
        if ($extGStates === []) {
            return $resources;
        }
        $extGStateDict = Dictionary::empty();
        foreach ($extGStates as $gsName => $entry) {
            $gsDict = Dictionary::empty()
                ->withEntry(Name::of('ca'), PdfNumber::ofFloat($entry['ca']))
                ->withEntry(Name::of('CA'), PdfNumber::ofFloat($entry['CA']));
            $extGStateDict = $extGStateDict->withEntry(Name::of($gsName), $gsDict);
        }
        return $resources->withEntry(Name::of('ExtGState'), $extGStateDict);
    }

    /**
     * Builds the two indirect objects for a filter raster: a DeviceRGB color
     * image whose /SMask points at a DeviceGray alpha image. Both sample
     * streams are raw 8-bit samples recompressed with /FlateDecode (gzcompress),
     * mirroring the alpha-PNG XObject pair in embedPng().
     *
     * @return array{IndirectObject, IndirectObject} [color image, alpha SMask]
     */
    private function buildFilterObjects(int $colorNum, int $smaskNum, EmbeddedFilter $filter): array
    {
        $colorCompressed = gzcompress($filter->colorBytes, 6);
        $alphaCompressed = gzcompress($filter->alphaBytes, 6);
        if ($colorCompressed === false || $alphaCompressed === false) {
            throw new PdfException('SVG filter raster compression failed');
        }

        $smaskDict = $this->xObjectBase($filter->widthPx, $filter->heightPx, 8, Name::of('DeviceGray'))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        $smaskObject = IndirectObject::of($smaskNum, 0, new ImageStream($smaskDict, $alphaCompressed));

        $colorDict = $this->xObjectBase($filter->widthPx, $filter->heightPx, 8, Name::of('DeviceRGB'))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'))
            ->withEntry(Name::of('SMask'), PdfReference::to($smaskNum, 0));
        $colorObject = IndirectObject::of($colorNum, 0, new ImageStream($colorDict, $colorCompressed));

        return [$colorObject, $smaskObject];
    }

    private function buildMaskFormObject(int $childNum, EmbeddedMask $emb): IndirectObject
    {
        // Nested smask refs inside a mask are out of scope; only ca/CA emitted.
        $resources = $this->resourcesWithExtGStates($emb->extGStates);
        if ($emb->patterns !== []) {
            $patternDict = Dictionary::empty();
            foreach ($emb->patterns as $patName => $dictStr) {
                $patternDict = $patternDict->withEntry(Name::of($patName), RawValue::of($dictStr));
            }
            $resources = $resources->withEntry(Name::of('Pattern'), $patternDict);
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
        $tileResources = $this->resourcesWithExtGStates($emb->extGStates);
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
