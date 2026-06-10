<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Walks the catalog /Pages tree and materializes ReadPage values with their
 * inherited attributes resolved (PDF 1.7 7.7.3).
 *
 * @internal
 */
final readonly class PageTreeReader
{
    /** @var list<float> */
    private const array DEFAULT_MEDIA_BOX = [0.0, 0.0, 612.0, 792.0]; // US Letter, lenient fallback

    /** @param \Closure(PdfObject): PdfObject $resolve */
    public function __construct(private \Closure $resolve) {}

    /** @return list<ReadPage> */
    public function collect(Dictionary $catalog): array
    {
        $rootValue = $catalog->get(Name::of('Pages'));
        if ($rootValue === null) {
            throw new PdfParseException('The catalog has no /Pages entry');
        }
        $root = ($this->resolve)($rootValue);
        if (!$root instanceof Dictionary) {
            throw new PdfParseException('/Pages does not resolve to a dictionary');
        }

        $pages = [];
        /** @var array{mediaBox: ?list<float>, cropBox: ?list<float>, rotate: ?int, resources: ?Dictionary} $inherited */
        $inherited = ['mediaBox' => null, 'cropBox' => null, 'rotate' => null, 'resources' => null];
        $visited = [];
        $this->walkPagesNode($root, $inherited, $visited, $pages, 0);
        return $pages;
    }

    /**
     * @param array{mediaBox: ?list<float>, cropBox: ?list<float>, rotate: ?int, resources: ?Dictionary} $inherited passed by value: each subtree gets its own copy
     * @param array<int, true> $visited keyed by kid object number
     * @param list<ReadPage> $pages
     */
    private function walkPagesNode(Dictionary $node, array $inherited, array &$visited, array &$pages, int $depth): void
    {
        if ($depth > 64) {
            throw new PdfParseException('Pages tree deeper than 64 levels (cycle suspected)');
        }
        $inherited['mediaBox'] = $this->boxEntry($node, 'MediaBox') ?? $inherited['mediaBox'];
        $inherited['cropBox'] = $this->boxEntry($node, 'CropBox') ?? $inherited['cropBox'];
        $inherited['rotate'] = DictReader::int($node, 'Rotate', $this->resolve) ?? $inherited['rotate'];
        $inherited['resources'] = DictReader::dictionary($node, 'Resources', $this->resolve) ?? $inherited['resources'];

        $kids = ($this->resolve)($node->get(Name::of('Kids')) ?? PdfNull::instance());
        $isPagesNode = DictReader::name($node, 'Type') === 'Pages' || $kids instanceof PdfArray;
        if (!$isPagesNode) {
            $pages[] = $this->makePage($node, $inherited);
            return;
        }
        if (!$kids instanceof PdfArray) {
            return; // a /Pages node with no kids: empty subtree
        }
        foreach ($kids->elements() as $kid) {
            if ($kid instanceof PdfReference) {
                if (isset($visited[$kid->objectNumber])) {
                    throw new PdfParseException("Pages tree cycle through object {$kid->objectNumber}");
                }
                $visited[$kid->objectNumber] = true;
            }
            $kidDict = ($this->resolve)($kid);
            if ($kidDict instanceof Dictionary) {
                $this->walkPagesNode($kidDict, $inherited, $visited, $pages, $depth + 1);
            }
        }
    }

    /**
     * @param array{mediaBox: ?list<float>, cropBox: ?list<float>, rotate: ?int, resources: ?Dictionary} $inherited
     */
    private function makePage(Dictionary $dict, array $inherited): ReadPage
    {
        $rotate = $inherited['rotate'] ?? 0;
        $rotate = (($rotate % 360) + 360) % 360;
        if ($rotate % 90 !== 0) {
            $rotate = 0;
        }
        return new ReadPage(
            dict: $dict,
            mediaBox: $inherited['mediaBox'] ?? self::DEFAULT_MEDIA_BOX,
            cropBox: $inherited['cropBox'],
            rotate: $rotate,
            resources: $inherited['resources'],
            contents: $this->contentsRefs($dict),
        );
    }

    /** @return ?list<float> corner-normalized [llx, lly, urx, ury] */
    private function boxEntry(Dictionary $dict, string $key): ?array
    {
        $value = $dict->get(Name::of($key));
        if ($value === null) {
            return null;
        }
        $value = ($this->resolve)($value);
        if (!$value instanceof PdfArray || count($value->elements()) !== 4) {
            return null;
        }
        $numbers = [];
        foreach ($value->elements() as $element) {
            $element = ($this->resolve)($element);
            if (!$element instanceof PdfNumber) {
                return null;
            }
            $numbers[] = (float) $element->value();
        }
        return [
            min($numbers[0], $numbers[2]),
            min($numbers[1], $numbers[3]),
            max($numbers[0], $numbers[2]),
            max($numbers[1], $numbers[3]),
        ];
    }

    /** @return list<PdfReference> */
    private function contentsRefs(Dictionary $page): array
    {
        $contents = $page->get(Name::of('Contents'));
        if ($contents === null) {
            return [];
        }
        if ($contents instanceof PdfReference) {
            $resolved = ($this->resolve)($contents);
            if ($resolved instanceof PdfArray) {
                $contents = $resolved;       // /Contents was a ref to an array
            } else {
                return $resolved instanceof ReadStream ? [$contents] : [];
            }
        }
        if (!$contents instanceof PdfArray) {
            return [];
        }
        $refs = [];
        foreach ($contents->elements() as $element) {
            if ($element instanceof PdfReference) {
                $refs[] = $element;
            }
        }
        return $refs;
    }
}
