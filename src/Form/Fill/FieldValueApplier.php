<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
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
 * Applies a value to a resolved AcroForm field, producing the indirect objects
 * to append in an incremental revision.
 *
 * Currently supports Text fields only; other types are handled in Tasks 8-9.
 *
 * @internal
 */
final class FieldValueApplier
{
    public function __construct(
        private readonly PdfReader $reader,
        private readonly MetricsRegistry $metrics,
    ) {}

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
            default => throw new PdfException(
                "FieldValueApplier: type not yet supported: {$rf->type->name} (field '{$rf->name}')",
            ),
        };
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
        $widgetResolved = $this->reader->resolve($this->reader->object($widgetNum));
        if (!$widgetResolved instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': widget object {$widgetNum} does not resolve to a Dictionary",
            );
        }
        $widgetDict = $widgetResolved;

        // 3. Read /Rect from widget dict
        $rectRaw = $widgetDict->get(Name::of('Rect'));
        if ($rectRaw === null) {
            throw new PdfException(
                "Field '{$rf->name}': widget object {$widgetNum} has no /Rect entry",
            );
        }
        $rectObj = $this->reader->resolve($rectRaw);
        if (!$rectObj instanceof PdfArray) {
            throw new PdfException(
                "Field '{$rf->name}': /Rect in widget object {$widgetNum} is not an array",
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
        $w = abs($urx - $llx);
        $h = abs($ury - $lly);

        // 4. Effective DA
        $daString = $rf->defaultAppearance ?? $this->acroFormDefaultDA() ?? '0 g /Helv 10 Tf';
        $da = DefaultAppearance::parse($daString);

        // 5. Resolve the DR font for the DA alias
        [$font, $drFontRef, $usedAlias] = $this->resolveDrFont($rf, $da->fontAlias);

        // 6. Read /Q quadding from widget dict (merged dict inherits from field)
        $q = DictReader::int($rf->dict, 'Q', $this->reader->resolve(...)) ?? 0;

        // 7. Build the appearance content stream
        $builder = new TextAppearanceBuilder($this->metrics);
        $result = $builder->build($value, $w, $h, $da, $font, $usedAlias, $q, $rf->isMultiline());
        $content = $result['content'];
        $bbox = $result['bbox'];

        // 8. Assemble the appearance stream object (Form XObject)
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
                    Dictionary::empty()->withEntry(Name::of($usedAlias), $drFontRef)));
        $apStream = CompressedStream::of($content, $apDict);
        $apNum = $allocate();
        $apObj = IndirectObject::of($apNum, 0, $apStream);

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

            $originalWidgetResolved = $this->reader->resolve($this->reader->object($widgetNum));
            if (!$originalWidgetResolved instanceof Dictionary) {
                throw new PdfException(
                    "Field '{$rf->name}': widget object {$widgetNum} does not resolve to a Dictionary on re-read",
                );
            }
            $widgetWithAp = $originalWidgetResolved
                ->withEntry(Name::of('AP'), $apAnnotDict);
            $objects[] = IndirectObject::of($widgetNum, 0, $widgetWithAp);
        }

        $objects[] = $apObj;

        return new AppliedField($objects);
    }

    /**
     * Reads the AcroForm-level /DA string (if present).
     */
    private function acroFormDefaultDA(): ?string
    {
        $catalog = $this->reader->catalog();
        $acroFormRaw = $catalog->get(Name::of('AcroForm'));
        if ($acroFormRaw === null) {
            return null;
        }
        $acroForm = $this->reader->resolve($acroFormRaw);
        if (!$acroForm instanceof Dictionary) {
            return null;
        }
        return DictReader::decodeText($acroForm->get(Name::of('DA')));
    }

    /**
     * Resolves the DR font for a given DA font alias. Returns [Font, PdfReference, usedAlias].
     * Falls back to 'Helv' when the requested alias is absent from /DR.
     *
     * @return array{0: Font, 1: PdfReference, 2: string}
     */
    private function resolveDrFont(ResolvedField $rf, string $alias): array
    {
        $catalog = $this->reader->catalog();
        $acroFormRaw = $catalog->get(Name::of('AcroForm'));
        if ($acroFormRaw === null) {
            throw new PdfException(
                "Field '{$rf->name}': no /AcroForm in catalog; cannot resolve /DR font",
            );
        }
        $acroForm = $this->reader->resolve($acroFormRaw);
        if (!$acroForm instanceof Dictionary) {
            throw new PdfException(
                "Field '{$rf->name}': /AcroForm is not a Dictionary",
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

        $font = $this->baseFontToFont($rf, $baseFont);

        return [$font, $drFontRef, $usedAlias];
    }

    /**
     * Maps a PDF /BaseFont name to a Font instance.
     * Only Standard 14 fonts are supported; a subset-prefixed or non-standard
     * BaseFont triggers a PdfException.
     */
    private function baseFontToFont(ResolvedField $rf, string $baseFont): Font
    {
        if (str_starts_with($baseFont, 'Helvetica')) {
            $font = Font::helvetica();
            if (str_contains($baseFont, 'Bold')) {
                $font = $font->bold();
            }
            if (str_contains($baseFont, 'Oblique')) {
                $font = $font->italic();
            }
            return $font;
        }

        if (str_starts_with($baseFont, 'Times')) {
            $font = Font::times();
            if (str_contains($baseFont, 'Bold')) {
                $font = $font->bold();
            }
            if (str_contains($baseFont, 'Italic')) {
                $font = $font->italic();
            }
            return $font;
        }

        if (str_starts_with($baseFont, 'Courier')) {
            $font = Font::courier();
            if (str_contains($baseFont, 'Bold')) {
                $font = $font->bold();
            }
            if (str_contains($baseFont, 'Oblique')) {
                $font = $font->italic();
            }
            return $font;
        }

        throw new PdfException(
            "Cannot generate appearance for field '{$rf->name}': its /DA font '{$baseFont}' is not a Standard 14 font; embedded-font appearances are not supported yet",
        );
    }
}
