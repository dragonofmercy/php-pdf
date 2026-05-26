<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Form\Action\FieldActions;
use DragonOfMercy\PhpPdf\TextAlign;
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
     * @param array<string, PdfReference> $standardFontRefs alias => reference, e.g. ['Helv' => ..., 'Cour' => ..., 'TiRo' => ...]. Must contain at least 'Helv'.
     * @return array{acroFormRef: PdfReference, objects: list<IndirectObject>}
     */
    public function emit(array $widgets, array $standardFontRefs, int &$nextId, string $context): array
    {
        if (!isset($standardFontRefs['Helv'])) {
            throw new PdfException(
                'AcroFormEmitter::emit() requires at minimum a "Helv" entry in $standardFontRefs',
            );
        }

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

        /** @var list<PdfReference> $calculationOrder */
        $calculationOrder = [];

        foreach ($nonRadios as $w) {
            $field = $w['field'];
            if ($field instanceof TextField) {
                $objects[] = $this->emitTextField($field, $w['widgetRef'], $w['pageHeightPt']);
                $topLevelRefs[] = $w['widgetRef'];
                if ($field->actions()?->hasCalculate() === true) {
                    $calculationOrder[] = $w['widgetRef'];
                }
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
            if ($field instanceof Combobox) {
                $objects[] = $this->emitCombobox($field, $w['widgetRef'], $w['pageHeightPt']);
                $topLevelRefs[] = $w['widgetRef'];
                if ($field->actions()?->hasCalculate() === true) {
                    $calculationOrder[] = $w['widgetRef'];
                }
                continue;
            }
            if ($field instanceof Listbox) {
                $objects[] = $this->emitListbox($field, $w['widgetRef'], $w['pageHeightPt']);
                $topLevelRefs[] = $w['widgetRef'];
                if ($field->actions()?->hasCalculate() === true) {
                    $calculationOrder[] = $w['widgetRef'];
                }
                continue;
            }
            if ($field instanceof PushButton) {
                $objects[] = $this->emitPushButton($field, $w['widgetRef'], $w['pageHeightPt']);
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
        $drFontDict = Dictionary::empty();
        foreach ($standardFontRefs as $alias => $ref) {
            $drFontDict = $drFontDict->withEntry(Name::of($alias), $ref);
        }
        $drDict = Dictionary::empty()->withEntry(Name::of('Font'), $drFontDict);
        $acroFormDict = Dictionary::empty()
            ->withEntry(Name::of('Fields'), PdfArray::of(...$topLevelRefs))
            ->withEntry(Name::of('NeedAppearances'), PdfBoolean::true())
            ->withEntry(Name::of('DA'), PdfString::of('0 g /Helv 10 Tf'))
            ->withEntry(Name::of('DR'), $drDict);
        if ($calculationOrder !== []) {
            $acroFormDict = $acroFormDict->withEntry(Name::of('CO'), PdfArray::of(...$calculationOrder));
        }
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
        $flags = (1 << 14) | (1 << 15); // NoToggleToOff (bit 15) + Radio (bit 16), per PDF 32000-1 Table 227
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
                ->withEntry(Name::of('Border'), $this->borderArray($widget->appearance()));
            $mk = $this->buildMK($widget->appearance());
            if ($mk !== null) {
                $kidDict = $kidDict->withEntry(Name::of('MK'), $mk);
            }
            $da = self::buildDA($widget->appearance());
            if ($da !== null) {
                $kidDict = $kidDict->withEntry(Name::of('DA'), PdfString::of($da));
            }
            $kidDict = $kidDict
                ->withEntry(Name::of('AS'), Name::of($state))
                ->withEntry(Name::of('AP'), $apDict);

            $aa = $this->buildAdditionalActions($widget->actions(), false, $group, 'Radio');
            if ($aa !== null) {
                $kidDict = $kidDict->withEntry(Name::of('AA'), $aa);
            }

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
        if ($f->password) {
            $flags |= 1 << 13; // Password, bit 14 (PDF 32000-1:2008 Table 228).
        }

        $dict = $this->baseWidgetDict($f, 'Tx', $widgetRef, $pageHeightPt, $flags)
            ->withEntry(Name::of('T'), PdfString::of($f->name));

        if ($f->appearance !== null && $f->appearance->align !== TextAlign::LEFT) {
            $q = $f->appearance->align === TextAlign::CENTER ? 1 : 2;
            $dict = $dict->withEntry(Name::of('Q'), PdfNumber::ofInt($q));
        }

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

        $aa = $this->buildAdditionalActions($f->actions(), true, $f->name, 'TextField');
        if ($aa !== null) {
            $dict = $dict->withEntry(Name::of('AA'), $aa);
        }

        return IndirectObject::of($widgetRef->objectNumber, 0, $dict);
    }

    private function emitPushButton(PushButton $f, PdfReference $widgetRef, float $pageHeightPt): IndirectObject
    {
        $flags = 1 << 16; // Pushbutton, bit 17 (PDF 32000-1:2008 Table 227).
        if ($f->readOnly) {
            $flags |= 1 << 0; // ReadOnly (bit 1).
        }

        $dict = $this->baseWidgetDict($f, 'Btn', $widgetRef, $pageHeightPt, $flags)
            ->withEntry(Name::of('T'), PdfString::of($f->name));

        // The button always needs an /MK carrying the caption (/CA); border and
        // background come from the appearance when present. withEntry replaces by
        // key, so this overwrites any /MK that baseWidgetDict may have added.
        $mk = ($this->buildMK($f->appearance()) ?? Dictionary::empty())
            ->withEntry(Name::of('CA'), PdfString::of($f->caption));
        $dict = $dict->withEntry(Name::of('MK'), $mk);

        $dict = $dict->withEntry(Name::of('A'), $this->buildButtonAction($f->action));

        if ($f->tooltip !== null) {
            $dict = $dict->withEntry(Name::of('TU'), PdfString::of($f->tooltip));
        }

        $aa = $this->buildAdditionalActions($f->actions(), false, $f->name, 'PushButton');
        if ($aa !== null) {
            $dict = $dict->withEntry(Name::of('AA'), $aa);
        }

        return IndirectObject::of($widgetRef->objectNumber, 0, $dict);
    }

    private function buildButtonAction(ButtonAction $action): Dictionary
    {
        return match ($action->type()) {
            ButtonActionType::OpenUrl => $this->actionDict('URI')
                ->withEntry(Name::of('URI'), PdfString::of($action->url() ?? throw new PdfException('OpenUrl action must carry a URL'))),
            ButtonActionType::ResetForm => $this->actionDict('ResetForm'),
            ButtonActionType::SubmitForm => $this->actionDict('SubmitForm')
                ->withEntry(Name::of('F'), Dictionary::empty()
                    ->withEntry(Name::of('FS'), Name::of('URL'))
                    ->withEntry(Name::of('F'), PdfString::of(
                        $action->url() ?? throw new PdfException('SubmitForm action must carry a URL'),
                    )))
                ->withEntry(Name::of('Flags'), PdfNumber::ofInt($action->flags() ?? 0)),
        };
    }

    /**
     * Starts an action dictionary with the shared `/Type /Action /S <subtype>`
     * header; callers add the subtype-specific entries.
     */
    private function actionDict(string $subtype): Dictionary
    {
        return Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Action'))
            ->withEntry(Name::of('S'), Name::of($subtype));
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

        $aa = $this->buildAdditionalActions($f->actions(), false, $f->name, 'Checkbox');
        if ($aa !== null) {
            $dict = $dict->withEntry(Name::of('AA'), $aa);
        }

        $widgetObj = IndirectObject::of($widgetRef->objectNumber, 0, $dict);
        return [$widgetObj, [$onObj, $offObj]];
    }

    private function emitCombobox(Combobox $f, PdfReference $widgetRef, float $pageHeightPt): IndirectObject
    {
        $flags = 1 << 17; // bit 18 Combo
        if ($f->readOnly) {
            $flags |= 1 << 0;
        }
        if ($f->required) {
            $flags |= 1 << 1;
        }
        if ($f->editable) {
            $flags |= 1 << 18;
        }

        $normalized = self::normalizeOptions($f->options);

        if ($f->value !== null && !self::optionsContainExport($normalized, $f->value)) {
            throw new PdfException(sprintf(
                "Combobox value '%s' not found in options for field '%s'",
                $f->value,
                $f->name,
            ));
        }

        $dict = $this->baseWidgetDict($f, 'Ch', $widgetRef, $pageHeightPt, $flags)
            ->withEntry(Name::of('T'), PdfString::of($f->name))
            ->withEntry(Name::of('Opt'), self::buildOptArray($normalized));

        if ($f->value !== null) {
            $dict = $dict->withEntry(Name::of('V'), PdfString::of($f->value));
            $dict = $dict->withEntry(Name::of('DV'), PdfString::of($f->value));
        }
        if ($f->tooltip !== null) {
            $dict = $dict->withEntry(Name::of('TU'), PdfString::of($f->tooltip));
        }

        $aa = $this->buildAdditionalActions($f->actions(), true, $f->name, 'Combobox');
        if ($aa !== null) {
            $dict = $dict->withEntry(Name::of('AA'), $aa);
        }

        return IndirectObject::of($widgetRef->objectNumber, 0, $dict);
    }

    private function emitListbox(Listbox $f, PdfReference $widgetRef, float $pageHeightPt): IndirectObject
    {
        $flags = 0; // NO Combo bit
        if ($f->readOnly) {
            $flags |= 1 << 0;
        }
        if ($f->required) {
            $flags |= 1 << 1;
        }
        if ($f->multiSelect) {
            $flags |= 1 << 21;
        }

        $normalized = self::normalizeOptions($f->options);

        // Normalize value into a list<string>.
        $values = $this->normalizeListboxValue($f, $normalized);

        $dict = $this->baseWidgetDict($f, 'Ch', $widgetRef, $pageHeightPt, $flags)
            ->withEntry(Name::of('T'), PdfString::of($f->name))
            ->withEntry(Name::of('Opt'), self::buildOptArray($normalized));

        if ($values !== []) {
            if (count($values) === 1 && !$f->multiSelect) {
                $dict = $dict->withEntry(Name::of('V'), PdfString::of($values[0]));
                $dict = $dict->withEntry(Name::of('DV'), PdfString::of($values[0]));
            } else {
                $items = [];
                foreach ($values as $v) {
                    $items[] = PdfString::of($v);
                }
                $dict = $dict->withEntry(Name::of('V'), PdfArray::of(...$items));
                $dict = $dict->withEntry(Name::of('DV'), PdfArray::of(...$items));
            }
        }
        if ($f->tooltip !== null) {
            $dict = $dict->withEntry(Name::of('TU'), PdfString::of($f->tooltip));
        }

        $aa = $this->buildAdditionalActions($f->actions(), true, $f->name, 'Listbox');
        if ($aa !== null) {
            $dict = $dict->withEntry(Name::of('AA'), $aa);
        }

        return IndirectObject::of($widgetRef->objectNumber, 0, $dict);
    }

    /**
     * @param list<array{export: string, label: string, hasDistinctLabel: bool}> $normalized
     * @return list<string>
     */
    private function normalizeListboxValue(Listbox $f, array $normalized): array
    {
        if ($f->value === null) {
            return [];
        }

        $values = is_string($f->value) ? [$f->value] : $f->value;
        if (!$f->multiSelect && count($values) > 1) {
            throw new PdfException(sprintf(
                "Listbox value must be a single string or null when multiSelect is false, got %d entries for field '%s'",
                count($values),
                $f->name,
            ));
        }

        foreach ($values as $v) {
            if (!self::optionsContainExport($normalized, $v)) {
                throw new PdfException(sprintf(
                    "Listbox value '%s' not found in options for field '%s'",
                    $v,
                    $f->name,
                ));
            }
        }
        return $values;
    }

    /**
     * @param list<string>|array<string, string> $options
     * @return list<array{export: string, label: string, hasDistinctLabel: bool}>
     */
    private static function normalizeOptions(array $options): array
    {
        $isAssoc = false;
        foreach ($options as $k => $_) {
            if (is_string($k)) {
                $isAssoc = true;
                break;
            }
        }
        $out = [];
        foreach ($options as $k => $v) {
            if ($isAssoc) {
                $out[] = ['export' => (string) $k, 'label' => $v, 'hasDistinctLabel' => true];
            } else {
                $out[] = ['export' => $v, 'label' => $v, 'hasDistinctLabel' => false];
            }
        }
        return $out;
    }

    /**
     * @param list<array{export: string, label: string, hasDistinctLabel: bool}> $normalized
     */
    private static function optionsContainExport(array $normalized, string $value): bool
    {
        foreach ($normalized as $opt) {
            if ($opt['export'] === $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{export: string, label: string, hasDistinctLabel: bool}> $normalized
     */
    private static function buildOptArray(array $normalized): PdfArray
    {
        $anyDistinct = false;
        foreach ($normalized as $opt) {
            if ($opt['hasDistinctLabel']) {
                $anyDistinct = true;
                break;
            }
        }
        $items = [];
        foreach ($normalized as $opt) {
            if ($anyDistinct) {
                $items[] = PdfArray::of(PdfString::of($opt['export']), PdfString::of($opt['label']));
            } else {
                $items[] = PdfString::of($opt['label']);
            }
        }
        return PdfArray::of(...$items);
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
            ->withEntry(Name::of('Border'), $this->borderArray($f->appearance()));
        $mk = $this->buildMK($f->appearance());
        if ($mk !== null) {
            $dict = $dict->withEntry(Name::of('MK'), $mk);
        }
        $da = self::buildDA($f->appearance());
        if ($da !== null) {
            $dict = $dict->withEntry(Name::of('DA'), PdfString::of($da));
        }
        if ($flags !== 0) {
            $dict = $dict->withEntry(Name::of('Ff'), PdfNumber::ofInt($flags));
        }
        return $dict;
    }

    /**
     * Maps a Standard 14 Font to its /AcroForm /DR alias. Throws for custom
     * (TTF) fonts; FieldAppearance.font is only allowed to reference one of
     * the Standard 14 in Phase 8.1.
     */
    private static function fontAlias(Font $font): string
    {
        if ($font->isCustom()) {
            throw new PdfException(
                'FieldAppearance.font must be one of the Standard 14 fonts (Helvetica, Courier, Times); custom TTF fonts are not supported in AcroForm /DR yet',
            );
        }
        $pdfName = $font->pdfName();
        if (str_starts_with($pdfName, 'Helvetica')) {
            return 'Helv';
        }
        if (str_starts_with($pdfName, 'Courier')) {
            return 'Cour';
        }
        if (str_starts_with($pdfName, 'Times')) {
            return 'TiRo';
        }
        throw new PdfException(sprintf(
            'Unsupported standard font for /AcroForm /DR: %s',
            $pdfName,
        ));
    }

    /**
     * Builds the /DA appearance string for a widget when its appearance
     * overrides textColor / font / fontSize. Returns null if no override
     * is needed (the form-level /DA "0 g /Helv 10 Tf" suffices).
     *
     * Format: "<color> /<alias> <size> Tf"
     */
    private static function buildDA(?FieldAppearance $appearance): ?string
    {
        if ($appearance === null) {
            return null;
        }
        if ($appearance->textColor === null && $appearance->font === null && $appearance->fontSize === null) {
            return null;
        }
        $color = $appearance->textColor !== null ? self::colorSetter($appearance->textColor) : '0 g';
        $alias = $appearance->font !== null ? self::fontAlias($appearance->font) : 'Helv';
        $size = $appearance->fontSize ?? 10.0;
        return sprintf('%s /%s %s Tf', $color, $alias, self::formatNum($size));
    }

    /**
     * Returns a PDF content-stream color-setting operator suitable for a /DA
     * string. Emits "L g" (DeviceGray) when r == g == b, otherwise
     * "R G B rg" (DeviceRGB).
     */
    private static function colorSetter(Color $c): string
    {
        $components = $c->rgbComponents();
        if ($components[0] === $components[1] && $components[1] === $components[2]) {
            return self::formatNum($components[0]) . ' g';
        }
        return sprintf(
            '%s %s %s rg',
            self::formatNum($components[0]),
            self::formatNum($components[1]),
            self::formatNum($components[2]),
        );
    }

    /**
     * Compact deterministic float formatting mirroring PdfNumber::ofFloat,
     * but reusable for substrings inside a /DA literal.
     */
    private static function formatNum(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        $formatted = rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * Builds /Border [HCornerRadius VCornerRadius Width]. When the appearance
     * has a non-null borderWidth, that width is used; otherwise 0 (no border
     * drawn by the reader). Integer values are emitted as integers to keep
     * the output compact and stable for byte-identity tests on widgets
     * without an appearance.
     */
    private function borderArray(?FieldAppearance $appearance): PdfArray
    {
        $width = ($appearance !== null && $appearance->borderWidth !== null) ? $appearance->borderWidth : 0.0;
        $widthEntry = ((float) (int) $width === $width)
            ? PdfNumber::ofInt((int) $width)
            : PdfNumber::ofFloat($width);
        return PdfArray::of(
            PdfNumber::ofInt(0),
            PdfNumber::ofInt(0),
            $widthEntry,
        );
    }

    /**
     * Builds the /MK appearance characteristics dict for a widget when the
     * appearance has at least one of borderColor / backgroundColor set.
     * Returns null when nothing to emit (keeps the widget dict slim).
     */
    private function buildMK(?FieldAppearance $appearance): ?Dictionary
    {
        if ($appearance === null) {
            return null;
        }
        if ($appearance->borderColor === null && $appearance->backgroundColor === null) {
            return null;
        }
        $mk = Dictionary::empty();
        if ($appearance->borderColor !== null) {
            $components = $appearance->borderColor->rgbComponents();
            $mk = $mk->withEntry(Name::of('BC'), PdfArray::of(
                PdfNumber::ofFloat($components[0]),
                PdfNumber::ofFloat($components[1]),
                PdfNumber::ofFloat($components[2]),
            ));
        }
        if ($appearance->backgroundColor !== null) {
            $components = $appearance->backgroundColor->rgbComponents();
            $mk = $mk->withEntry(Name::of('BG'), PdfArray::of(
                PdfNumber::ofFloat($components[0]),
                PdfNumber::ofFloat($components[1]),
                PdfNumber::ofFloat($components[2]),
            ));
        }
        return $mk;
    }

    /**
     * Builds the /AA dictionary for a field, or null when there are no actions.
     * Enforces that value triggers (K/F/V/C) appear only on text-like fields.
     */
    private function buildAdditionalActions(?FieldActions $actions, bool $allowValueTriggers, string $fieldName, string $fieldType): ?Dictionary
    {
        if ($actions === null) {
            return null;
        }
        $scripts = $actions->scripts();
        if ($scripts === []) {
            return null;
        }
        $valueTriggers = ['K' => true, 'F' => true, 'V' => true, 'C' => true];
        $dict = Dictionary::empty();
        foreach ($scripts as $trigger => $js) {
            if (!$allowValueTriggers && isset($valueTriggers[$trigger])) {
                throw new PdfException(sprintf(
                    "Field '%s': format/calculate/validate/keystroke actions are not valid on a %s",
                    $fieldName,
                    $fieldType,
                ));
            }
            $dict = $dict->withEntry(Name::of($trigger), Dictionary::empty()
                ->withEntry(Name::of('Type'), Name::of('Action'))
                ->withEntry(Name::of('S'), Name::of('JavaScript'))
                ->withEntry(Name::of('JS'), PdfString::of($js)));
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
