<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Flatten;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Form\Fill\FieldValueApplier;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Burns each target field's widget appearance into the content stream of the
 * page it sits on and strips the widgets from those pages' /Annots, returning
 * the objects to append and the flattened field object numbers (the caller
 * removes them from /AcroForm /Fields). Existing appearance streams are
 * referenced by object number (never copied); only freshly generated
 * appearances become new objects.
 *
 * @internal
 */
final class FieldFlattener
{
    public function __construct(
        private readonly PdfReader $reader,
        private readonly FieldValueApplier $applier,
    ) {}

    /**
     * @param list<FlattenTarget> $targets
     * @param callable(): int $allocate
     */
    public function flatten(array $targets, callable $allocate): FlattenResult
    {
        /** @var list<int> $removedFieldObjectNumbers */
        $removedFieldObjectNumbers = [];
        /** @var array<int, true> $flattenedWidgets widget object number => true */
        $flattenedWidgets = [];
        /** @var list<IndirectObject> $extraObjects generated appearance XObjects */
        $extraObjects = [];
        /**
         * Burns keyed by widget object number: the appearance reference and the
         * cm matrix to place it.
         * @var array<int, array{ref: PdfReference, cm: list<float>}> $burns
         */
        $burns = [];

        foreach ($targets as $target) {
            $field = $target->field;
            $removedFieldObjectNumbers[] = $field->objectNumber;

            foreach ($field->widgetObjectNumbers as $widgetNum) {
                $flattenedWidgets[$widgetNum] = true;
                $widgetDict = $this->reader->resolve($this->reader->object($widgetNum));
                if (!$widgetDict instanceof Dictionary) {
                    throw new PdfException("Field '{$field->name}': widget {$widgetNum} is not a dictionary");
                }
                $rect = $this->widgetRect($widgetDict, $widgetNum, $field->name);
                ['ref' => $ref, 'bbox' => $bbox, 'matrix' => $matrix, 'newObject' => $newObject]
                    = $this->appearanceFor($target, $widgetDict, $rect, $allocate);
                if ($newObject !== null) {
                    $extraObjects[] = $newObject;
                }
                $burns[$widgetNum] = [
                    'ref' => $ref,
                    'cm' => AppearancePlacement::matrix($bbox, $matrix, $rect),
                ];
            }
        }

        $pageObjects = $this->rewritePages($flattenedWidgets, $burns, $allocate, $extraObjects);

        return new FlattenResult([...$pageObjects, ...$extraObjects], $removedFieldObjectNumbers);
    }

    /**
     * Resolves the appearance to burn for one widget. When the target requests
     * regeneration (filled this session) or the widget has no usable /AP, the
     * appearance is generated via FieldValueApplier and returned as a new object
     * (BBox = the rect size, identity matrix). Otherwise the existing /AP /N
     * stream is referenced by number, selecting the /AS state for checkbox/radio.
     *
     * @param list<float> $rect
     * @param callable(): int $allocate
     * @return array{ref: PdfReference, bbox: list<float>, matrix: list<float>, newObject: ?IndirectObject}
     */
    private function appearanceFor(FlattenTarget $target, Dictionary $widgetDict, array $rect, callable $allocate): array
    {
        $field = $target->field;
        $type = $field->type;
        $isTextOrChoice = $type === FormFieldType::Text
            || $type === FormFieldType::Combobox
            || $type === FormFieldType::Listbox;

        $existing = $this->existingAppearanceRef($widgetDict, $target);

        if ($isTextOrChoice && ($target->regenerate || $existing === null)) {
            $generated = $this->generateAppearance($target, $allocate);
            $rectW = abs($rect[2] - $rect[0]);
            $rectH = abs($rect[3] - $rect[1]);
            return [
                'ref' => PdfReference::to($generated->objectNumber, 0),
                'bbox' => [0.0, 0.0, $rectW, $rectH],
                'matrix' => [1.0, 0.0, 0.0, 1.0, 0.0, 0.0],
                'newObject' => $generated,
            ];
        }

        if ($existing === null) {
            throw new PdfException(
                "Cannot flatten field '{$field->name}': widget has no appearance stream to burn",
            );
        }

        $streamObj = $this->reader->resolve(PdfReference::to($existing, 0));
        if (!$streamObj instanceof ReadStream) {
            throw new PdfException(
                "Cannot flatten field '{$field->name}': appearance object {$existing} is not a stream",
            );
        }

        return [
            'ref' => PdfReference::to($existing, 0),
            'bbox' => $this->streamBBox($streamObj),
            'matrix' => $this->streamMatrix($streamObj),
            'newObject' => null,
        ];
    }

    /**
     * Returns the object number of the existing /AP /N appearance stream for a
     * widget, selecting the current on/off state for checkbox/radio from the
     * target's final value, or null when none is referencable.
     */
    private function existingAppearanceRef(Dictionary $widget, FlattenTarget $target): ?int
    {
        $ap = $widget->get(Name::of('AP'));
        $ap = $ap !== null ? $this->reader->resolve($ap) : null;
        if (!$ap instanceof Dictionary) {
            return null;
        }
        $n = $ap->get(Name::of('N'));
        if ($n instanceof PdfReference) {
            return $n->objectNumber; // text / choice: a single stream
        }
        $n = $n !== null ? $this->reader->resolve($n) : null;
        if (!$n instanceof Dictionary) {
            return null;
        }
        // Checkbox / radio: pick the stream for the wanted state.
        $state = $this->wantedState($target, $n);
        $stateRef = $n->get(Name::of($state));
        return $stateRef instanceof PdfReference ? $stateRef->objectNumber : null;
    }

    /**
     * The /AS state name to burn for a checkbox/radio widget given the final
     * value: the matching on-state when selected, else 'Off'.
     */
    private function wantedState(FlattenTarget $target, Dictionary $nDict): string
    {
        $onState = null;
        foreach ($nDict->entries() as [$key, $_v]) {
            if ($key->value() !== 'Off') {
                $onState = $key->value();
                break;
            }
        }
        if ($onState === null) {
            return 'Off';
        }
        $value = $target->value;
        if ($target->field->type === FormFieldType::Checkbox) {
            return $value === true ? $onState : 'Off';
        }
        // Radio: value is the chosen export name; this widget is on iff it matches.
        return (is_string($value) && $value === $onState) ? $onState : 'Off';
    }

    /**
     * Generates the appearance XObject for a text/choice field via the fill
     * pipeline and returns the appearance IndirectObject (a CompressedStream).
     * The field re-emit object produced by the applier is discarded - the field
     * is being stripped.
     *
     * @param callable(): int $allocate
     */
    private function generateAppearance(FlattenTarget $target, callable $allocate): IndirectObject
    {
        $value = $target->value ?? '';
        $applied = $this->applier->apply($target->field, $value, $allocate);
        foreach ($applied->objects as $obj) {
            if ($obj->payload() instanceof CompressedStream) {
                return $obj;
            }
        }
        throw new PdfException(
            "Internal error: no appearance stream generated for field '{$target->field->name}'",
        );
    }

    /**
     * Re-emits every page that hosts a flattened widget: appends a burn content
     * stream to /Contents, registers the appearances in /Resources /XObject, and
     * removes the flattened widgets from /Annots.
     *
     * @param array<int, true> $flattenedWidgets
     * @param array<int, array{ref: PdfReference, cm: list<float>}> $burns
     * @param callable(): int $allocate
     * @param list<IndirectObject> $extraObjects burned content streams are appended here
     * @return list<IndirectObject>
     */
    private function rewritePages(array $flattenedWidgets, array $burns, callable $allocate, array &$extraObjects): array
    {
        $pages = [];
        $pageCount = $this->reader->pageCount();
        for ($i = 1; $i <= $pageCount; $i++) {
            $readPage = $this->reader->page($i);
            $annots = $readPage->dict->get(Name::of('Annots'));
            $annots = $annots !== null ? $this->reader->resolve($annots) : null;
            if (!$annots instanceof PdfArray) {
                continue;
            }

            $keptAnnots = [];
            $pageBurns = [];
            foreach ($annots->elements() as $el) {
                if ($el instanceof PdfReference && isset($flattenedWidgets[$el->objectNumber], $burns[$el->objectNumber])) {
                    $pageBurns[] = $burns[$el->objectNumber];
                    continue;
                }
                $keptAnnots[] = $el;
            }
            if ($pageBurns === []) {
                continue;
            }

            $pages[] = $this->rewriteOnePage(
                $i,
                $readPage->dict,
                $readPage->resources,
                $readPage->contents,
                $keptAnnots,
                $pageBurns,
                $allocate,
                $extraObjects,
            );
        }
        return $pages;
    }

    /**
     * Builds the re-emitted page object plus its burn content stream.
     *
     * @param list<\DragonOfMercy\PhpPdf\Writer\Object\PdfReference> $contents
     * @param list<PdfObject> $keptAnnots
     * @param list<array{ref: PdfReference, cm: list<float>}> $pageBurns
     * @param callable(): int $allocate
     * @param list<IndirectObject> $extraObjects
     */
    private function rewriteOnePage(
        int $pageNumber,
        Dictionary $pageDict,
        ?Dictionary $resources,
        array $contents,
        array $keptAnnots,
        array $pageBurns,
        callable $allocate,
        array &$extraObjects,
    ): IndirectObject {
        $pageObjectNumber = $this->pageObjectNumber($pageNumber);

        // Register each burn under a fresh /XObject name, build the burn content.
        $resources ??= Dictionary::empty();
        $xobjects = $resources->get(Name::of('XObject'));
        $xobjects = $xobjects instanceof Dictionary ? $xobjects : Dictionary::empty();
        $existingNames = [];
        foreach ($xobjects->entries() as [$key, $_v]) {
            $existingNames[$key->value()] = true;
        }

        $content = '';
        $counter = 0;
        foreach ($pageBurns as $burn) {
            do {
                $name = 'PXFlat' . $counter++;
            } while (isset($existingNames[$name]));
            $existingNames[$name] = true;
            $xobjects = $xobjects->withEntry(Name::of($name), $burn['ref']);
            [$a, $b, $c, $d, $e, $f] = $burn['cm'];
            $content .= 'q ' . self::num($a) . ' ' . self::num($b) . ' ' . self::num($c) . ' '
                . self::num($d) . ' ' . self::num($e) . ' ' . self::num($f) . ' cm /' . $name . " Do Q\n";
        }

        $burnStreamNumber = $allocate();
        $extraObjects[] = IndirectObject::of($burnStreamNumber, 0, CompressedStream::of($content));

        $newContents = [...$contents, PdfReference::to($burnStreamNumber, 0)];

        $updated = $pageDict
            ->withEntry(Name::of('Resources'), $resources->withEntry(Name::of('XObject'), $xobjects))
            ->withEntry(Name::of('Contents'), PdfArray::of(...$newContents))
            ->withEntry(Name::of('Annots'), PdfArray::of(...$keptAnnots));

        return IndirectObject::of($pageObjectNumber, 0, $updated);
    }

    private static function num(float $v): string
    {
        return PdfNumber::ofFloat($v)->toBytes();
    }

    /**
     * The object number of the 1-based page, by walking the page tree leaf refs.
     */
    private function pageObjectNumber(int $pageNumber): int
    {
        $refs = $this->pageRefs();
        $ref = $refs[$pageNumber - 1] ?? null;
        if ($ref === null) {
            throw new PdfException("Cannot flatten: page {$pageNumber} not found");
        }
        return $ref->objectNumber;
    }

    /** @var list<PdfReference>|null */
    private ?array $cachedPageRefs = null;

    /** @return list<PdfReference> */
    private function pageRefs(): array
    {
        if ($this->cachedPageRefs !== null) {
            return $this->cachedPageRefs;
        }
        $pagesRef = $this->reader->catalog()->get(Name::of('Pages'));
        if (!$pagesRef instanceof PdfReference) {
            throw new PdfException('The opened PDF has no indirect /Pages reference');
        }
        $out = [];
        $this->collectPageRefs($pagesRef, $out, 0);
        return $this->cachedPageRefs = $out;
    }

    /** @param list<PdfReference> $out */
    private function collectPageRefs(PdfReference $nodeRef, array &$out, int $depth): void
    {
        if ($depth > 50) {
            throw new PdfException('Page tree nested too deeply (possible cycle)');
        }
        $node = $this->reader->resolve($nodeRef);
        if (!$node instanceof Dictionary) {
            return;
        }
        $type = $node->get(Name::of('Type'));
        if ($type instanceof Name && $type->value() === 'Pages') {
            $kids = $node->get(Name::of('Kids'));
            $kids = $kids !== null ? $this->reader->resolve($kids) : null;
            if ($kids instanceof PdfArray) {
                foreach ($kids->elements() as $kid) {
                    if ($kid instanceof PdfReference) {
                        $this->collectPageRefs($kid, $out, $depth + 1);
                    }
                }
            }
            return;
        }
        $out[] = $nodeRef;
    }

    /**
     * Reads a widget's /Rect as corner-normalized [llx, lly, urx, ury].
     *
     * @return list<float>
     */
    private function widgetRect(Dictionary $widget, int $widgetNum, string $fieldName): array
    {
        $rect = $widget->get(Name::of('Rect'));
        $rect = $rect !== null ? $this->reader->resolve($rect) : null;
        if (!$rect instanceof PdfArray || count($rect->elements()) !== 4) {
            throw new PdfException("Field '{$fieldName}': widget {$widgetNum} has no usable /Rect");
        }
        $c = [];
        foreach ($rect->elements() as $el) {
            $n = $this->reader->resolve($el);
            if (!$n instanceof PdfNumber) {
                throw new PdfException("Field '{$fieldName}': /Rect has a non-numeric element");
            }
            $c[] = (float) $n->value();
        }
        return [min($c[0], $c[2]), min($c[1], $c[3]), max($c[0], $c[2]), max($c[1], $c[3])];
    }

    /**
     * Reads /BBox from an appearance stream, defaulting to [0,0,0,0] when absent.
     *
     * @return list<float>
     */
    private function streamBBox(ReadStream $stream): array
    {
        return $this->readNumberArray($stream->dict, 'BBox', 4, [0.0, 0.0, 0.0, 0.0]);
    }

    /**
     * Reads /Matrix from an appearance stream, defaulting to identity when absent.
     *
     * @return list<float>
     */
    private function streamMatrix(ReadStream $stream): array
    {
        return $this->readNumberArray($stream->dict, 'Matrix', 6, [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]);
    }

    /**
     * Reads a dict entry expected to be an array of exactly $count numbers,
     * returning $default (a copy) when absent, the wrong type, or the wrong
     * length. Non-numeric elements become 0.0.
     *
     * @param list<float> $default
     * @return list<float>
     */
    private function readNumberArray(Dictionary $dict, string $key, int $count, array $default): array
    {
        $raw = $dict->get(Name::of($key));
        $raw = $raw !== null ? $this->reader->resolve($raw) : null;
        if (!$raw instanceof PdfArray || count($raw->elements()) !== $count) {
            return $default;
        }
        $out = [];
        foreach ($raw->elements() as $el) {
            $n = $this->reader->resolve($el);
            $out[] = $n instanceof PdfNumber ? (float) $n->value() : 0.0;
        }
        return $out;
    }
}
