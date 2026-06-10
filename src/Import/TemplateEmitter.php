<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Import;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\PdfObjectAllocator;

/**
 * Wraps an imported page into a Form XObject: /BBox is the page box in the
 * page's own coordinates and /Matrix maps it - including the /Rotate baked
 * in clockwise - onto an upright [0, 0, visualW, visualH] box, so Page can
 * place it with a plain scale-and-translate CTM like an image. /Resources
 * and everything reachable from them are deep-copied with new object numbers.
 *
 * @internal
 */
final readonly class TemplateEmitter
{
    /**
     * @return array{xobject: IndirectObject, objects: list<IndirectObject>}
     *         xobject: the Form XObject; objects: the copied resource subgraph
     */
    public function emit(ImportedPageTemplate $template, PdfObjectAllocator $allocator): array
    {
        $reader = $template->reader();
        $page = $template->page();
        $xobjectNumber = $allocator->next();
        $copier = new ObjectCopier($reader, $allocator);

        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Form'))
            ->withEntry(Name::of('FormType'), PdfNumber::ofInt(1))
            ->withEntry(Name::of('BBox'), self::numberArray($page->box()))
            ->withEntry(Name::of('Matrix'), self::numberArray(self::matrixFor($page->box(), $page->rotate)));

        if ($page->resources !== null) {
            $dict = $dict->withEntry(Name::of('Resources'), $copier->copy($page->resources));
        }
        $group = $page->dict->get(Name::of('Group'));
        if ($group !== null) {
            $dict = $dict->withEntry(Name::of('Group'), $copier->copy($group));
        }

        [$data, $filter] = $this->assembleContents($template);
        if ($filter !== null) {
            $dict = $dict->withEntry(Name::of('Filter'), $filter);
        }

        return [
            'xobject' => IndirectObject::of($xobjectNumber, 0, new ReadStream($dict, $data)),
            'objects' => $copier->collectedObjects(),
        ];
    }

    /**
     * @return array{0: string, 1: ?PdfObject} raw payload + /Filter value (with
     *         matching encoding) - single source stream copied verbatim,
     *         multiple streams decoded, joined, and re-Flated
     */
    private function assembleContents(ImportedPageTemplate $template): array
    {
        $reader = $template->reader();
        $refs = $template->page()->contents;
        if ($refs === []) {
            return ['', null];
        }
        if (count($refs) === 1) {
            $stream = $reader->resolve($refs[0]);
            if (!$stream instanceof ReadStream) {
                return ['', null];
            }
            $filter = $stream->dict->get(Name::of('Filter'));
            if ($filter !== null) {
                // keep raw bytes only when we can also carry /DecodeParms faithfully
                $parms = $stream->dict->get(Name::of('DecodeParms')) ?? $stream->dict->get(Name::of('DP'));
                if ($parms === null) {
                    return [$stream->rawData(), $filter];
                }
            } else {
                return [$stream->rawData(), null];
            }
            // filtered with DecodeParms: normalize by decoding + re-Flating below
        }
        $parts = [];
        foreach ($refs as $ref) {
            $stream = $reader->resolve($ref);
            if ($stream instanceof ReadStream) {
                $parts[] = $reader->decodeStream($stream);
            }
        }
        $joined = implode("\n", $parts);
        $compressed = gzcompress($joined, 9);
        if ($compressed === false) {
            throw new PdfException('Failed to compress imported template content');
        }
        return [$compressed, Name::of('FlateDecode')];
    }

    /**
     * @param list<float> $box [llx, lly, urx, ury]
     * @return list<float> [a, b, c, d, e, f]
     */
    private static function matrixFor(array $box, int $rotate): array
    {
        [$llx, $lly, $urx, $ury] = $box;
        return match ($rotate) {
            90 => [0.0, -1.0, 1.0, 0.0, -$lly, $urx],
            180 => [-1.0, 0.0, 0.0, -1.0, $urx, $ury],
            270 => [0.0, 1.0, -1.0, 0.0, $ury, -$llx],
            default => [1.0, 0.0, 0.0, 1.0, -$llx, -$lly],
        };
    }

    /**
     * @param list<float> $values
     */
    private static function numberArray(array $values): PdfArray
    {
        $numbers = [];
        foreach ($values as $value) {
            $numbers[] = $value === floor($value)
                ? PdfNumber::ofInt((int) $value)
                : PdfNumber::ofFloat($value);
        }
        return PdfArray::of(...$numbers);
    }
}
