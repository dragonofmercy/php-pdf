<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Builds the `/AcroForm` dictionary and one IndirectObject per widget for
 * a document's interactive form fields. Stateless modulo the document Unit.
 *
 * Input: list of `{field, widgetRef, pageRef, pageHeightPt}` collected by
 * Document::buildPagesFontsImages(). The caller is responsible for using
 * the returned `acroFormRef` in the catalog dictionary and appending the
 * returned IndirectObjects to the document object pool.
 *
 * @internal
 */
final readonly class AcroFormEmitter
{
    public function __construct(private Unit $unit) {}

    /**
     * @param list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}> $widgets
     * @return array{acroFormRef: PdfReference, objects: list<IndirectObject>}
     */
    public function emit(array $widgets, int &$nextId, string $context): array
    {
        $this->validateUniqueNames($widgets, $context);

        $objects = [];
        $topLevelRefs = [];

        /** @var array<string, list<array{radio: Radio, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}>> $radiosByGroup */
        $radiosByGroup = [];
        /** @var list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}> $nonRadios */
        $nonRadios = [];
        foreach ($widgets as $w) {
            if ($w['field'] instanceof Radio) {
                $radiosByGroup[$w['field']->group][] = [
                    'radio' => $w['field'],
                    'widgetRef' => $w['widgetRef'],
                    'pageRef' => $w['pageRef'],
                    'pageHeightPt' => $w['pageHeightPt'],
                ];
            } else {
                $nonRadios[] = $w;
            }
        }

        foreach ($nonRadios as $w) {
            $field = $w['field'];
            if ($field instanceof TextField) {
                $objects[] = $this->emitTextField($field, $w['widgetRef'], $w['pageHeightPt']);
                $topLevelRefs[] = $w['widgetRef'];
                continue;
            }
            if ($field instanceof Checkbox) {
                [$widgetObj, $apObjs] = $this->emitCheckbox($field, $w['widgetRef'], $w['pageHeightPt'], $nextId);
                $objects[] = $widgetObj;
                foreach ($apObjs as $ap) {
                    $objects[] = $ap;
                }
                $topLevelRefs[] = $w['widgetRef'];
                continue;
            }
            throw new PdfException(sprintf(
                'AcroFormEmitter: unsupported field type %s for %s',
                $field::class,
                $context,
            ));
        }

        foreach ($radiosByGroup as $group => $radios) {
            [$parentObj, $kidObjs, $apObjs, $parentRef] = $this->emitRadioGroup($group, $radios, $nextId);
            $objects[] = $parentObj;
            foreach ($kidObjs as $k) {
                $objects[] = $k;
            }
            foreach ($apObjs as $ap) {
                $objects[] = $ap;
            }
            $topLevelRefs[] = $parentRef;
        }

        $acroFormId = $nextId++;
        $acroFormDict = Dictionary::empty()
            ->withEntry(Name::of('Fields'), PdfArray::of(...$topLevelRefs))
            ->withEntry(Name::of('NeedAppearances'), PdfBoolean::true());
        $objects[] = IndirectObject::of($acroFormId, 0, $acroFormDict);

        return [
            'acroFormRef' => PdfReference::to($acroFormId, 0),
            'objects' => $objects,
        ];
    }

    /**
     * @param list<array{radio: Radio, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}> $radios
     * @return array{0: IndirectObject, 1: list<IndirectObject>, 2: list<IndirectObject>, 3: PdfReference}
     */
    private function emitRadioGroup(string $group, array $radios, int &$nextId): array
    {
        if ($radios === []) {
            throw new PdfException(sprintf("Radio group '%s' has no widgets", $group));
        }

        $parentId = $nextId++;
        $parentRef = PdfReference::to($parentId, 0);

        $kidRefs = [];
        foreach ($radios as $r) {
            $kidRefs[] = $r['widgetRef'];
        }

        // Determine selected value (last checked wins).
        $selectedValue = null;
        foreach ($radios as $r) {
            if ($r['radio']->checked) {
                $selectedValue = $r['radio']->value;
            }
        }

        // Compute parent flags. ReadOnly/Required taken from any checked widget; if
        // none, take first.
        $sourceForFlags = $radios[0]['radio'];
        foreach ($radios as $r) {
            if ($r['radio']->checked) {
                $sourceForFlags = $r['radio'];
                break;
            }
        }
        $flags = (1 << 15) | (1 << 16); // NoToggleToOff + Radio
        if ($sourceForFlags->readOnly) {
            $flags |= 1 << 0;
        }
        if ($sourceForFlags->required) {
            $flags |= 1 << 1;
        }

        $parentDict = Dictionary::empty()
            ->withEntry(Name::of('FT'), Name::of('Btn'))
            ->withEntry(Name::of('T'), PdfString::of($group))
            ->withEntry(Name::of('Ff'), PdfNumber::ofInt($flags))
            ->withEntry(Name::of('Kids'), PdfArray::of(...$kidRefs));
        if ($selectedValue !== null) {
            $parentDict = $parentDict->withEntry(Name::of('V'), Name::of($selectedValue));
            $parentDict = $parentDict->withEntry(Name::of('DV'), Name::of($selectedValue));
        } else {
            $parentDict = $parentDict->withEntry(Name::of('V'), Name::of('Off'));
        }
        $parentObj = IndirectObject::of($parentId, 0, $parentDict);

        // Emit each kid widget with /Parent, /AS, /AP, no /T.
        $kidObjs = [];
        $apObjs = [];
        foreach ($radios as $r) {
            $widget = $r['radio'];
            $widgetRef = $r['widgetRef'];

            $onId = $nextId++;
            $offId = $nextId++;
            $d = $widget->dimensions();
            $wPt = $this->unit->toPoints($d['width']);
            $hPt = $this->unit->toPoints($d['height']);
            $textColor = ($widget->appearance !== null && $widget->appearance->textColor !== null)
                ? $widget->appearance->textColor
                : Color::rgb(0, 0, 0);
            $apContent = RadioAppearance::generate($wPt, $hPt, $textColor);
            $onStream = $this->buildAppearanceStream($apContent['onContent'], $apContent['bbox']);
            $offStream = $this->buildAppearanceStream($apContent['offContent'], $apContent['bbox']);
            $apObjs[] = IndirectObject::of($onId, 0, $onStream);
            $apObjs[] = IndirectObject::of($offId, 0, $offStream);

            $apDict = Dictionary::empty()
                ->withEntry(Name::of('N'), Dictionary::empty()
                    ->withEntry(Name::of($widget->value), PdfReference::to($onId, 0))
                    ->withEntry(Name::of('Off'), PdfReference::to($offId, 0)));

            $state = ($widget->value === $selectedValue) ? $widget->value : 'Off';
            $kidDict = Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Annot'))
                ->withEntry(Name::of('Subtype'), Name::of('Widget'))
                ->withEntry(Name::of('Parent'), $parentRef)
                ->withEntry(Name::of('Rect'), $this->computeRect($widget, $r['pageHeightPt']))
                ->withEntry(Name::of('Border'), PdfArray::of(PdfNumber::ofInt(0), PdfNumber::ofInt(0), PdfNumber::ofInt(0)))
                ->withEntry(Name::of('AS'), Name::of($state))
                ->withEntry(Name::of('AP'), $apDict);

            $kidObjs[] = IndirectObject::of($widgetRef->objectNumber, 0, $kidDict);
        }

        return [$parentObj, $kidObjs, $apObjs, $parentRef];
    }

    /**
     * @param list<array{field: FormField, widgetRef: PdfReference, pageRef: PdfReference, pageHeightPt: float}> $widgets
     */
    private function validateUniqueNames(array $widgets, string $context): void
    {
        /** @var array<string, list<string>> $seen kind list per name */
        $seen = [];
        foreach ($widgets as $w) {
            $name = $w['field']->name();
            $kind = $w['field']::class;
            $seen[$name][] = $kind;
        }
        foreach ($seen as $name => $kinds) {
            if (count($kinds) === 1) {
                continue;
            }
            // Allowed iff every entry is a Radio (grouped).
            foreach ($kinds as $k) {
                if ($k !== Radio::class) {
                    throw new PdfException(sprintf(
                        "Duplicate field name '%s' (kinds: %s) in %s",
                        $name,
                        implode(', ', array_unique($kinds)),
                        $context,
                    ));
                }
            }
        }
    }

    private function emitTextField(TextField $f, PdfReference $widgetRef, float $pageHeightPt): IndirectObject
    {
        $flags = 0;
        if ($f->readOnly) {
            $flags |= 1 << 0;   // bit 1: ReadOnly (mask 1)
        }
        if ($f->required) {
            $flags |= 1 << 1;   // bit 2: Required (mask 2)
        }
        if ($f->multiline) {
            $flags |= 1 << 12;  // bit 13: Multiline (mask 4096)
        }

        $dict = $this->baseWidgetDict($f, 'Tx', $widgetRef, $pageHeightPt, $flags)
            ->withEntry(Name::of('T'), PdfString::of($f->name));

        if ($f->value !== '') {
            $dict = $dict->withEntry(Name::of('V'), PdfString::of($f->value));
            $dict = $dict->withEntry(Name::of('DV'), PdfString::of($f->value));
        }
        if ($f->tooltip !== null) {
            $dict = $dict->withEntry(Name::of('TU'), PdfString::of($f->tooltip));
        }
        if ($f->maxLength !== null) {
            $dict = $dict->withEntry(Name::of('MaxLen'), PdfNumber::ofInt($f->maxLength));
        }

        return IndirectObject::of($widgetRef->objectNumber, 0, $dict);
    }

    /**
     * @return array{0: IndirectObject, 1: list<IndirectObject>}
     */
    private function emitCheckbox(Checkbox $f, PdfReference $widgetRef, float $pageHeightPt, int &$nextId): array
    {
        $flags = 0;
        if ($f->readOnly) {
            $flags |= 1 << 0;
        }
        if ($f->required) {
            $flags |= 1 << 1;
        }

        $onId = $nextId++;
        $offId = $nextId++;

        $d = $f->dimensions();
        $wPt = $this->unit->toPoints($d['width']);
        $hPt = $this->unit->toPoints($d['height']);
        $textColor = $f->appearance !== null && $f->appearance->textColor !== null
            ? $f->appearance->textColor
            : Color::rgb(0, 0, 0);
        $apContent = CheckboxAppearance::generate($wPt, $hPt, $textColor);

        $onStream = $this->buildAppearanceStream($apContent['onContent'], $apContent['bbox']);
        $offStream = $this->buildAppearanceStream($apContent['offContent'], $apContent['bbox']);
        $onObj = IndirectObject::of($onId, 0, $onStream);
        $offObj = IndirectObject::of($offId, 0, $offStream);

        $apDict = Dictionary::empty()
            ->withEntry(Name::of('N'), Dictionary::empty()
                ->withEntry(Name::of('On'), PdfReference::to($onId, 0))
                ->withEntry(Name::of('Off'), PdfReference::to($offId, 0)));

        $state = $f->checked ? 'On' : 'Off';
        $dict = $this->baseWidgetDict($f, 'Btn', $widgetRef, $pageHeightPt, $flags)
            ->withEntry(Name::of('T'), PdfString::of($f->name))
            ->withEntry(Name::of('AS'), Name::of($state))
            ->withEntry(Name::of('AP'), $apDict);

        if ($f->checked) {
            $dict = $dict->withEntry(Name::of('V'), Name::of('On'));
            $dict = $dict->withEntry(Name::of('DV'), Name::of('On'));
        }
        if ($f->tooltip !== null) {
            $dict = $dict->withEntry(Name::of('TU'), PdfString::of($f->tooltip));
        }

        $widgetObj = IndirectObject::of($widgetRef->objectNumber, 0, $dict);
        return [$widgetObj, [$onObj, $offObj]];
    }

    /**
     * @param array{float, float, float, float} $bbox
     */
    private function buildAppearanceStream(string $content, array $bbox): CompressedStream
    {
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Form'))
            ->withEntry(Name::of('BBox'), PdfArray::of(
                PdfNumber::ofFloat($bbox[0]),
                PdfNumber::ofFloat($bbox[1]),
                PdfNumber::ofFloat($bbox[2]),
                PdfNumber::ofFloat($bbox[3]),
            ));
        return CompressedStream::of($content, $dict);
    }

    private function baseWidgetDict(FormField $f, string $ftName, PdfReference $widgetRef, float $pageHeightPt, int $flags): Dictionary
    {
        $rect = $this->computeRect($f, $pageHeightPt);
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Annot'))
            ->withEntry(Name::of('Subtype'), Name::of('Widget'))
            ->withEntry(Name::of('FT'), Name::of($ftName))
            ->withEntry(Name::of('Rect'), $rect)
            ->withEntry(Name::of('Border'), PdfArray::of(
                PdfNumber::ofInt(0),
                PdfNumber::ofInt(0),
                PdfNumber::ofInt(0),
            ));
        if ($flags !== 0) {
            $dict = $dict->withEntry(Name::of('Ff'), PdfNumber::ofInt($flags));
        }
        return $dict;
    }

    private function computeRect(FormField $f, float $pageHeightPt): PdfArray
    {
        $d = $f->dimensions();

        $xPt = $this->unit->toPoints($d['x']);
        $yPt = $this->unit->toPoints($d['y']);
        $wPt = $this->unit->toPoints($d['width']);
        $hPt = $this->unit->toPoints($d['height']);

        $llx = $xPt;
        $lly = $pageHeightPt - ($yPt + $hPt);
        $urx = $xPt + $wPt;
        $ury = $pageHeightPt - $yPt;

        return PdfArray::of(
            PdfNumber::ofFloat($llx),
            PdfNumber::ofFloat($lly),
            PdfNumber::ofFloat($urx),
            PdfNumber::ofFloat($ury),
        );
    }
}
