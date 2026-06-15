<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Fill\Font\AppearanceFont;
use DragonOfMercy\PhpPdf\Form\Fill\Font\AppearanceFontFactory;
use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;

/**
 * Applies a validated value to a resolved AcroForm field, producing the indirect objects
 * to append in an incremental revision (re-emitted field/widget objects plus any generated
 * appearance Form XObjects).
 *
 * @internal
 */
final class FieldValueApplier
{
    /** Cached /AcroForm default DA string; false = not yet resolved, null = absent. */
    private string|false|null $cachedDefaultDA = false;

    /** Cached /AcroForm dictionary; false = not yet resolved, null = absent. */
    private Dictionary|false|null $cachedAcroForm = false;

    /** @var array<string, array{0: AppearanceFont, 1: PdfReference, 2: string}> Cache of successfully resolved DR fonts keyed by alias. */
    private array $drFontCache = [];

    private readonly TextAppearanceBuilder $textBuilder;
    private readonly ListboxAppearanceBuilder $listboxBuilder;

    public function __construct(
        private readonly PdfReader $reader,
        private readonly MetricsRegistry $metrics,
    ) {
        $this->textBuilder = new TextAppearanceBuilder();
        $this->listboxBuilder = new ListboxAppearanceBuilder();
    }

    /**
     * Applies $value to $rf and returns the objects to write in the incremental
     * revision. $allocate() returns the next free object number (for new
     * appearance streams).
     *
     * @param string|bool|array<mixed> $value
     * @param callable(): int $allocate
     */
    public function apply(ResolvedField $rf, string|bool|array $value, callable $allocate): AppliedField
    {
        return match ($rf->type) {
            FormFieldType::Text => $this->applyText($rf, $value, $allocate),
            FormFieldType::Checkbox => $this->applyCheckbox($rf, $value),
            FormFieldType::Radio => $this->applyRadio($rf, $value),
            FormFieldType::Combobox => $this->applyCombobox($rf, $value, $allocate),
            FormFieldType::Listbox => $this->applyListbox($rf, $value, $allocate),
            default => throw new PdfException(
                "FieldValueApplier: type not yet supported: {$rf->type->name} (field '{$rf->name}')",
            ),
        };
    }

    /**
     * @param string|bool|array<mixed> $value
     */
    private function applyCheckbox(ResolvedField $rf, string|bool|array $value): AppliedField
    {
        if (!is_bool($value)) {
            throw new PdfException(
                "Field '{$rf->name}' is a checkbox; value must be a bool",
            );
        }

        // The checkbox field and its single widget are the same object.
        if ($rf->widgetObjectNumbers === []) {
            throw new PdfException("Field '{$rf->name}' has no widget annotation");
        }
        $widgetNum = $rf->widgetObjectNumbers[0];
        $widgetResolved = $this->reader->resolve($this->reader->object($widgetNum));
        if (!$widgetResolved instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': checkbox widget object {$widgetNum} does not resolve to a Dictionary",
            );
        }

        $onState = $this->onStateName($widgetResolved);
        if ($onState === null) {
            throw new PdfException(
                "Field '{$rf->name}': checkbox widget object {$widgetNum} has no non-Off key in /AP /N; cannot determine on-state",
            );
        }

        $target = $value ? $onState : 'Off';

        $updated = $widgetResolved
            ->withEntry(Name::of('V'), Name::of($target))
            ->withEntry(Name::of('AS'), Name::of($target));

        return new AppliedField([IndirectObject::of($widgetNum, 0, $updated)]);
    }

    /**
     * @param string|bool|array<mixed> $value
     */
    private function applyRadio(ResolvedField $rf, string|bool|array $value): AppliedField
    {
        if (!is_string($value)) {
            $options = implode(', ', $rf->options);
            throw new PdfException(
                "Field '{$rf->name}' is a radio group; value must be a string (one of: {$options})",
            );
        }

        // Build a map: kid object number -> on-state name (the non-Off /AP /N key).
        /** @var array<int, string> $kidOnState */
        $kidOnState = [];
        foreach ($rf->widgetObjectNumbers as $kidNum) {
            $kidResolved = $this->reader->resolve($this->reader->object($kidNum));
            if (!$kidResolved instanceof Dictionary) {
                continue;
            }
            $onState = $this->onStateName($kidResolved);
            if ($onState !== null) {
                $kidOnState[$kidNum] = $onState;
            }
        }

        // Validate that the chosen value matches one of the kids' on-states.
        $allOnStates = array_values($kidOnState);
        if (!in_array($value, $allOnStates, true)) {
            $optionList = implode(', ', $allOnStates !== [] ? $allOnStates : $rf->options);
            throw new PdfException(
                "Field '{$rf->name}': '{$value}' is not a valid option (expected one of: {$optionList})",
            );
        }

        $objects = [];

        // Re-emit the group parent with /V set to the chosen value.
        $groupResolved = $this->reader->resolve($this->reader->object($rf->objectNumber));
        if (!$groupResolved instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': radio group object {$rf->objectNumber} does not resolve to a Dictionary",
            );
        }
        $objects[] = IndirectObject::of(
            $rf->objectNumber,
            0,
            $groupResolved->withEntry(Name::of('V'), Name::of($value)),
        );

        // Re-emit each kid with /AS set to its on-state or 'Off'.
        foreach ($rf->widgetObjectNumbers as $kidNum) {
            $kidResolved = $this->reader->resolve($this->reader->object($kidNum));
            if (!$kidResolved instanceof Dictionary) {
                continue;
            }
            $onState = $kidOnState[$kidNum] ?? null;
            $as = ($onState === $value) ? $value : 'Off';
            $objects[] = IndirectObject::of(
                $kidNum,
                0,
                $kidResolved->withEntry(Name::of('AS'), Name::of($as)),
            );
        }

        return new AppliedField($objects);
    }

    /**
     * Returns the non-'Off' key name from /AP /N of a widget dict, or null when
     * /AP or /N is absent or all keys are 'Off'.
     */
    private function onStateName(Dictionary $widgetDict): ?string
    {
        $apRaw = $widgetDict->get(Name::of('AP'));
        if ($apRaw === null) {
            return null;
        }
        $ap = $this->reader->resolve($apRaw);
        if (!$ap instanceof Dictionary) {
            return null;
        }
        $nRaw = $ap->get(Name::of('N'));
        if ($nRaw === null) {
            return null;
        }
        $n = $this->reader->resolve($nRaw);
        if (!$n instanceof Dictionary) {
            return null;
        }
        foreach ($n->entries() as [$key, $_value]) {
            if ($key->value() !== 'Off') {
                return $key->value();
            }
        }
        return null;
    }

    /**
     * Builds an export->display map and export->index map from /Opt in the merged field dict.
     *
     * /Opt elements are either:
     *   - a plain text string (export == display), or
     *   - a 2-element PdfArray [export, display].
     *
     * @return array{exportToDisplay: array<string, string>, exportToIndex: array<string, int>}
     */
    private function parseOptMap(ResolvedField $rf): array
    {
        $optRaw = $rf->dict->get(Name::of('Opt'));
        if ($optRaw === null) {
            return ['exportToDisplay' => [], 'exportToIndex' => []];
        }
        $opt = $this->reader->resolve($optRaw);
        if (!$opt instanceof PdfArray) {
            return ['exportToDisplay' => [], 'exportToIndex' => []];
        }
        $exportToDisplay = [];
        $exportToIndex = [];
        foreach ($opt->elements() as $i => $element) {
            $resolved = $this->reader->resolve($element);
            if ($resolved instanceof PdfArray) {
                $subelements = $resolved->elements();
                $export = DictReader::decodeText($this->reader->resolve($subelements[0] ?? Name::of(''))) ?? '';
                $display = DictReader::decodeText($this->reader->resolve($subelements[1] ?? Name::of(''))) ?? $export;
            } else {
                $text = DictReader::decodeText($resolved) ?? '';
                $export = $text;
                $display = $text;
            }
            $exportToDisplay[$export] = $display;
            $exportToIndex[$export] = $i;
        }
        return ['exportToDisplay' => $exportToDisplay, 'exportToIndex' => $exportToIndex];
    }

    /**
     * @param string|bool|array<mixed> $value
     * @param callable(): int $allocate
     */
    private function applyCombobox(ResolvedField $rf, string|bool|array $value, callable $allocate): AppliedField
    {
        // 1. Validate value type
        if (!is_string($value)) {
            $options = implode(', ', $rf->options);
            throw new PdfException(
                "Field '{$rf->name}' is a combobox; value must be a string (one of: {$options})",
            );
        }

        // 2. Validate value is a known export value
        if (!in_array($value, $rf->options, true)) {
            $options = implode(', ', $rf->options);
            throw new PdfException(
                "Field '{$rf->name}': '{$value}' is not a valid option (expected one of: {$options})",
            );
        }

        // 3. Get the display text for this export value
        $optMap = $this->parseOptMap($rf);
        $displayText = $optMap['exportToDisplay'][$value] ?? $value;

        // 4. Determine the widget object number (combobox = field == widget, single object)
        $widgetNum = $rf->widgetObjectNumbers[0] ?? $rf->objectNumber;

        // 5. Resolve widget dict and read /Rect to get w,h
        ['w' => $w, 'h' => $h] = $this->widgetRect($rf, $widgetNum);

        // 6. Effective DA
        $daString = $rf->defaultAppearance ?? $this->acroFormDefaultDA() ?? '0 g /Helv 10 Tf';
        $da = DefaultAppearance::parse($daString);

        // 7. Resolve DR font
        [$font, $drFontRef, $usedAlias] = $this->resolveDrFont($rf, $da->fontAlias);

        // 8. Quadding
        $q = DictReader::int($rf->dict, 'Q', $this->reader->resolve(...)) ?? 0;

        // 9. Build single-line appearance using TextAppearanceBuilder
        $result = $this->textBuilder->build($displayText, $w, $h, $da, $font, $usedAlias, $q, false);
        $content = $result['content'];
        $bbox = $result['bbox'];

        // 10. Assemble Form XObject
        [$apNum, $apObj] = $this->buildAppearanceXObject($content, $bbox, $usedAlias, $drFontRef, $allocate);

        // 11. Re-emit the field/widget object from its ORIGINAL dict
        $fieldObjNum = $rf->objectNumber;
        $fieldResolved = $this->reader->resolve($this->reader->object($fieldObjNum));
        if (!$fieldResolved instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': field object {$fieldObjNum} does not resolve to a Dictionary",
            );
        }
        $apAnnotDict = Dictionary::empty()
            ->withEntry(Name::of('N'), PdfReference::to($apNum, 0));

        $combined = $fieldResolved
            ->withEntry(Name::of('V'), TextString::of($value))
            ->withEntry(Name::of('AP'), $apAnnotDict);

        return new AppliedField([IndirectObject::of($fieldObjNum, 0, $combined), $apObj]);
    }

    /**
     * @param string|bool|array<mixed> $value
     * @param callable(): int $allocate
     */
    private function applyListbox(ResolvedField $rf, string|bool|array $value, callable $allocate): AppliedField
    {
        // 1. Validate and normalize value
        if (is_bool($value)) {
            throw new PdfException(
                "Field '{$rf->name}' is a listbox; value must be a string or array of strings",
            );
        }

        if (is_array($value)) {
            if (!$rf->isMultiSelect()) {
                throw new PdfException(
                    "Field '{$rf->name}' is single-select; value must be a string",
                );
            }
            if (array_filter($value, static fn ($e): bool => !is_string($e)) !== []) {
                throw new PdfException(
                    "Field '{$rf->name}': listbox values must be strings",
                );
            }
            /** @var list<string> $selected */
            $selected = array_values($value);
        } else {
            $selected = [$value];
        }

        // 2. Validate all selected values exist in options
        $optMap = $this->parseOptMap($rf);
        $exportToDisplay = $optMap['exportToDisplay'];
        $exportToIndex = $optMap['exportToIndex'];

        foreach ($selected as $sel) {
            if (!in_array($sel, $rf->options, true)) {
                $options = implode(', ', $rf->options);
                throw new PdfException(
                    "Field '{$rf->name}': '{$sel}' is not a valid option (expected one of: {$options})",
                );
            }
        }

        // 3. Build /V value object
        if (count($selected) === 1) {
            $vObject = TextString::of($selected[0]);
        } else {
            $vObject = PdfArray::of(...array_map(TextString::of(...), $selected));
        }

        // 4. Build /I indices (sorted ascending)
        $indices = [];
        foreach ($selected as $sel) {
            if (isset($exportToIndex[$sel])) {
                $indices[] = $exportToIndex[$sel];
            }
        }
        sort($indices);
        $iObject = PdfArray::of(...array_map(PdfNumber::ofInt(...), $indices));

        // 5. Determine widget and read /Rect
        $widgetNum = $rf->widgetObjectNumbers[0] ?? $rf->objectNumber;
        ['w' => $w, 'h' => $h] = $this->widgetRect($rf, $widgetNum);

        // 6. Effective DA
        $daString = $rf->defaultAppearance ?? $this->acroFormDefaultDA() ?? '0 g /Helv 10 Tf';
        $da = DefaultAppearance::parse($daString);

        // 7. Resolve DR font
        [$font, $drFontRef, $usedAlias] = $this->resolveDrFont($rf, $da->fontAlias);

        // 8. Build display options list (preserving /Opt order)
        $displayOptions = [];
        foreach ($rf->options as $exportVal) {
            $displayOptions[] = $exportToDisplay[$exportVal] ?? $exportVal;
        }

        // 9. Build listbox appearance
        $result = $this->listboxBuilder->build($displayOptions, $indices, $w, $h, $da, $usedAlias, $font);
        $content = $result['content'];
        $bbox = $result['bbox'];

        // 10. Assemble Form XObject
        [$apNum, $apObj] = $this->buildAppearanceXObject($content, $bbox, $usedAlias, $drFontRef, $allocate);

        // 11. Re-emit field/widget object from ORIGINAL dict
        $fieldObjNum = $rf->objectNumber;
        $fieldResolved = $this->reader->resolve($this->reader->object($fieldObjNum));
        if (!$fieldResolved instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': field object {$fieldObjNum} does not resolve to a Dictionary",
            );
        }
        $apAnnotDict = Dictionary::empty()
            ->withEntry(Name::of('N'), PdfReference::to($apNum, 0));

        $combined = $fieldResolved
            ->withEntry(Name::of('V'), $vObject)
            ->withEntry(Name::of('I'), $iObject)
            ->withEntry(Name::of('AP'), $apAnnotDict);

        return new AppliedField([IndirectObject::of($fieldObjNum, 0, $combined), $apObj]);
    }

    /**
     * @param string|bool|array<mixed> $value
     * @param callable(): int $allocate
     */
    private function applyText(ResolvedField $rf, string|bool|array $value, callable $allocate): AppliedField
    {
        // 1. Validate value type
        if (!is_string($value)) {
            throw new PdfException(
                "Field '{$rf->name}' is a text field; value must be a string",
            );
        }

        // 2. Determine the widget object number (text field = single widget)
        $widgetNum = $rf->widgetObjectNumbers[0] ?? $rf->objectNumber;

        // 3. Resolve widget dict and read /Rect
        ['dict' => $widgetDict, 'w' => $w, 'h' => $h] = $this->widgetRect($rf, $widgetNum);

        // 4. Effective DA
        $daString = $rf->defaultAppearance ?? $this->acroFormDefaultDA() ?? '0 g /Helv 10 Tf';
        $da = DefaultAppearance::parse($daString);

        // 5. Resolve the DR font for the DA alias
        [$font, $drFontRef, $usedAlias] = $this->resolveDrFont($rf, $da->fontAlias);

        // 6. Read /Q quadding from widget dict (merged dict inherits from field)
        $q = DictReader::int($rf->dict, 'Q', $this->reader->resolve(...)) ?? 0;

        // 7. Build the appearance content stream
        $result = $this->textBuilder->build($value, $w, $h, $da, $font, $usedAlias, $q, $rf->isMultiline());
        $content = $result['content'];
        $bbox = $result['bbox'];

        // 8. Assemble the appearance stream object (Form XObject)
        [$apNum, $apObj] = $this->buildAppearanceXObject($content, $bbox, $usedAlias, $drFontRef, $allocate);

        // 9. Re-emit field/widget object(s)
        // Start from the ORIGINAL field dict (not the merged rf->dict)
        $fieldObjNum = $rf->objectNumber;
        $fieldResolved = $this->reader->resolve($this->reader->object($fieldObjNum));
        if (!$fieldResolved instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': field object {$fieldObjNum} does not resolve to a Dictionary",
            );
        }
        $originalFieldDict = $fieldResolved;

        $apAnnotDict = Dictionary::empty()
            ->withEntry(Name::of('N'), PdfReference::to($apNum, 0));

        $objects = [];

        if ($fieldObjNum === $widgetNum) {
            // Field and widget are the same object: one IndirectObject with both /V and /AP
            $combined = $originalFieldDict
                ->withEntry(Name::of('V'), TextString::of($value))
                ->withEntry(Name::of('AP'), $apAnnotDict);
            $objects[] = IndirectObject::of($fieldObjNum, 0, $combined);
        } else {
            // Different objects: field gets /V, widget gets /AP
            $fieldWithV = $originalFieldDict
                ->withEntry(Name::of('V'), TextString::of($value));
            $objects[] = IndirectObject::of($fieldObjNum, 0, $fieldWithV);

            // Reuse the already-resolved widget dict (read at step 2-3) for the re-emit
            $widgetWithAp = $widgetDict
                ->withEntry(Name::of('AP'), $apAnnotDict);
            $objects[] = IndirectObject::of($widgetNum, 0, $widgetWithAp);
        }

        $objects[] = $apObj;

        return new AppliedField($objects);
    }

    /**
     * Builds a Form XObject appearance stream as a CompressedStream wrapped in an
     * IndirectObject, allocating a new object number via $allocate.
     *
     * Returns [$apNum, $apObj]. The dict entries are inserted in this exact order:
     * Type, Subtype, BBox, Resources/Font -- preserving byte-identical output.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     * @param callable(): int $allocate
     * @return array{0: int, 1: IndirectObject}
     */
    private function buildAppearanceXObject(
        string $content,
        array $bbox,
        string $alias,
        PdfReference $fontRef,
        callable $allocate,
    ): array {
        $apDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XObject'))
            ->withEntry(Name::of('Subtype'), Name::of('Form'))
            ->withEntry(Name::of('BBox'), PdfArray::of(
                PdfNumber::ofFloat($bbox[0]),
                PdfNumber::ofFloat($bbox[1]),
                PdfNumber::ofFloat($bbox[2]),
                PdfNumber::ofFloat($bbox[3]),
            ))
            ->withEntry(Name::of('Resources'), Dictionary::empty()
                ->withEntry(Name::of('Font'),
                    Dictionary::empty()->withEntry(Name::of($alias), $fontRef)));
        $apStream = CompressedStream::of($content, $apDict);
        $apNum = $allocate();
        return [$apNum, IndirectObject::of($apNum, 0, $apStream)];
    }

    /**
     * Resolves the widget dict for $widgetObjectNumber and extracts abs width/height from /Rect.
     *
     * Throws PdfException with field-name context if the object is not a Dictionary,
     * /Rect is absent, not a PdfArray, does not have exactly 4 elements, or contains
     * a non-numeric element.
     *
     * @return array{dict: Dictionary, w: float, h: float}
     */
    private function widgetRect(ResolvedField $rf, int $widgetObjectNumber): array
    {
        $widgetResolved = $this->reader->resolve($this->reader->object($widgetObjectNumber));
        if (!$widgetResolved instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': widget object {$widgetObjectNumber} does not resolve to a Dictionary",
            );
        }

        $rectRaw = $widgetResolved->get(Name::of('Rect'));
        if ($rectRaw === null) {
            throw new PdfException(
                "Field '{$rf->name}': widget object {$widgetObjectNumber} has no /Rect entry",
            );
        }
        $rectObj = $this->reader->resolve($rectRaw);
        if (!$rectObj instanceof PdfArray) {
            throw new PdfException(
                "Field '{$rf->name}': /Rect in widget object {$widgetObjectNumber} is not an array",
            );
        }
        $rectElements = $rectObj->elements();
        if (count($rectElements) !== 4) {
            throw new PdfException(
                "Field '{$rf->name}': /Rect must have 4 numbers, got " . count($rectElements),
            );
        }
        $coords = [];
        foreach ($rectElements as $el) {
            $resolved = $this->reader->resolve($el);
            if (!$resolved instanceof PdfNumber) {
                throw new PdfException(
                    "Field '{$rf->name}': /Rect contains a non-numeric element",
                );
            }
            $coords[] = (float) $resolved->value();
        }
        [$llx, $lly, $urx, $ury] = $coords;

        return ['dict' => $widgetResolved, 'w' => abs($urx - $llx), 'h' => abs($ury - $lly)];
    }

    /**
     * Reads the AcroForm-level /DA string (if present). Result is memoized:
     * false sentinel guards against re-walking the catalog when the value
     * is legitimately null.
     */
    private function acroFormDefaultDA(): ?string
    {
        if ($this->cachedDefaultDA === false) {
            $acroForm = $this->resolvedAcroForm();
            $this->cachedDefaultDA = $acroForm !== null
                ? DictReader::decodeText($acroForm->get(Name::of('DA')))
                : null;
        }
        return $this->cachedDefaultDA;
    }

    /**
     * Returns the resolved /AcroForm Dictionary from the catalog, or null when
     * absent. Memoized on first call.
     */
    private function resolvedAcroForm(): ?Dictionary
    {
        if ($this->cachedAcroForm === false) {
            $acroFormRaw = $this->reader->catalog()->get(Name::of('AcroForm'));
            if ($acroFormRaw === null) {
                $this->cachedAcroForm = null;
            } else {
                $resolved = $this->reader->resolve($acroFormRaw);
                $this->cachedAcroForm = $resolved instanceof Dictionary ? $resolved : null;
            }
        }
        return $this->cachedAcroForm;
    }

    /**
     * Resolves the DR font for a given DA font alias. Returns [AppearanceFont, PdfReference, usedAlias].
     * Falls back to 'Helv' when the requested alias is absent from /DR.
     * Successful resolutions are cached by alias.
     *
     * @return array{0: AppearanceFont, 1: PdfReference, 2: string}
     */
    private function resolveDrFont(ResolvedField $rf, string $alias): array
    {
        if (isset($this->drFontCache[$alias])) {
            return $this->drFontCache[$alias];
        }

        $acroForm = $this->resolvedAcroForm();
        if ($acroForm === null) {
            throw new PdfException(
                "Field '{$rf->name}': no /AcroForm in catalog; cannot resolve /DR font",
            );
        }

        $drRaw = $acroForm->get(Name::of('DR'));
        if ($drRaw === null) {
            throw new PdfException(
                "Field '{$rf->name}': /AcroForm has no /DR entry",
            );
        }
        $dr = $this->reader->resolve($drRaw);
        if (!$dr instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': /DR is not a Dictionary",
            );
        }

        $fontDictRaw = $dr->get(Name::of('Font'));
        if ($fontDictRaw === null) {
            throw new PdfException(
                "Field '{$rf->name}': /DR has no /Font entry",
            );
        }
        $fontDict = $this->reader->resolve($fontDictRaw);
        if (!$fontDict instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': /DR /Font is not a Dictionary",
            );
        }

        // Try the requested alias; fall back to 'Helv'
        $usedAlias = $alias;
        $fontEntryRaw = $fontDict->get(Name::of($alias));
        if ($fontEntryRaw === null) {
            $usedAlias = 'Helv';
            $fontEntryRaw = $fontDict->get(Name::of('Helv'));
        }
        if ($fontEntryRaw === null) {
            throw new PdfException(
                "Field '{$rf->name}': /DR /Font has neither /{$alias} nor /Helv entry",
            );
        }

        // The entry must be a PdfReference to the font indirect object
        if (!$fontEntryRaw instanceof PdfReference) {
            throw new PdfException(
                "Field '{$rf->name}': /DR /Font /{$usedAlias} is not a reference",
            );
        }
        $drFontRef = $fontEntryRaw;

        // Read the font object to find /BaseFont
        $fontObjRaw = $this->reader->resolve($this->reader->object($drFontRef->objectNumber));
        if (!$fontObjRaw instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': DR font object {$drFontRef->objectNumber} is not a Dictionary",
            );
        }
        $baseFont = DictReader::name($fontObjRaw, 'BaseFont', $this->reader->resolve(...));
        if ($baseFont === null) {
            throw new PdfException(
                "Field '{$rf->name}': DR font object {$drFontRef->objectNumber} has no /BaseFont",
            );
        }

        $font = AppearanceFontFactory::forField(
            $this->reader,
            $fontObjRaw,
            $baseFont,
            $rf->name,
            $this->metrics,
        );

        $result = [$font, $drFontRef, $usedAlias];
        $this->drFontCache[$alias] = $result;
        return $result;
    }

}
