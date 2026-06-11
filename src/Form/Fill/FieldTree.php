<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;

/**
 * Walks the AcroForm field tree of an opened PDF, resolving inheritance and
 * returning one {@see ResolvedField} per terminal field.
 *
 * @internal
 */
final class FieldTree
{
    private const int MAX_DEPTH = 50;

    public function __construct(private readonly PdfReader $reader) {}

    /**
     * Returns all terminal AcroForm fields from the opened PDF, with
     * inheritance applied. Returns an empty list when the document has no
     * /AcroForm, or the /AcroForm has no /Fields.
     *
     * @return list<ResolvedField>
     */
    public function terminalFields(): array
    {
        $catalog = $this->reader->catalog();
        $acroFormRaw = $catalog->get(Name::of('AcroForm'));
        if ($acroFormRaw === null) {
            return [];
        }

        $acroForm = $this->reader->resolve($acroFormRaw);
        if (!$acroForm instanceof Dictionary) {
            return [];
        }

        $fieldsRaw = $acroForm->get(Name::of('Fields'));
        if ($fieldsRaw === null) {
            return [];
        }
        $fields = $this->reader->resolve($fieldsRaw);
        if (!$fields instanceof PdfArray) {
            return [];
        }

        // Seed the inherited /DA from the AcroForm dict
        $inheritedDa = $this->extractString($acroForm->get(Name::of('DA')));

        /** @var array{ft: ?string, ff: int, v: ?PdfObject, da: ?string} $inherited */
        $inherited = ['ft' => null, 'ff' => 0, 'v' => null, 'da' => $inheritedDa];

        $results = [];
        foreach ($fields->elements() as $fieldRef) {
            $resolved = $this->reader->resolve($fieldRef);
            $objNum = $fieldRef instanceof PdfReference ? $fieldRef->objectNumber : 0;
            if (!$resolved instanceof Dictionary) {
                continue;
            }
            $this->walkNode($objNum, $resolved, '', $inherited, $results, 0);
        }

        return $results;
    }

    /**
     * @param array{ft: ?string, ff: int, v: ?PdfObject, da: ?string} $inherited
     * @param list<ResolvedField> $results
     */
    private function walkNode(
        int $objNum,
        Dictionary $dict,
        string $qualifiedPrefix,
        array $inherited,
        array &$results,
        int $depth,
    ): void {
        if ($depth > self::MAX_DEPTH) {
            throw new PdfException('AcroForm field tree depth exceeds ' . self::MAX_DEPTH . '; possible circular reference');
        }

        // Merge inheritable attributes: own value wins over inherited
        $ft = DictReader::name($dict, 'FT', $this->reader->resolve(...)) ?? $inherited['ft'];
        $ownFf = DictReader::int($dict, 'Ff', $this->reader->resolve(...));
        $ff = $ownFf !== null ? $ownFf : $inherited['ff'];
        $ownDa = $this->extractString($dict->get(Name::of('DA')));
        $da = $ownDa ?? $inherited['da'];

        // Build qualified name from /T partial name
        $partialT = $this->extractString($dict->get(Name::of('T')));
        if ($partialT !== null && $partialT !== '') {
            $qualifiedName = $qualifiedPrefix !== '' ? $qualifiedPrefix . '.' . $partialT : $partialT;
        } else {
            $qualifiedName = $qualifiedPrefix;
        }

        // Check for /Kids
        $kidsRaw = $dict->get(Name::of('Kids'));
        if ($kidsRaw === null) {
            // No kids: this is a terminal field that is its own single widget
            $resolved = $this->buildResolvedField($objNum, $dict, [$objNum], $qualifiedName, $ft, $ff, $da);
            if ($resolved !== null) {
                $results[] = $resolved;
            }
            return;
        }

        $kids = $this->reader->resolve($kidsRaw);
        if (!$kids instanceof PdfArray) {
            return;
        }

        $kidElements = $kids->elements();
        if ($kidElements === []) {
            return;
        }

        // Determine if kids have /T (non-terminal = recurse) or no /T (terminal = widgets)
        // A field's kids that carry /T are sub-fields; kids without /T are widget annotations
        $firstKidWithT = false;
        foreach ($kidElements as $kidRef) {
            $kidDict = $this->reader->resolve($kidRef);
            if (!$kidDict instanceof Dictionary) {
                continue;
            }
            $kidT = $this->extractString($kidDict->get(Name::of('T')));
            if ($kidT !== null && $kidT !== '') {
                $firstKidWithT = true;
                break;
            }
        }

        if ($firstKidWithT) {
            // Non-terminal: recurse into each kid as a sub-field
            $childInherited = ['ft' => $ft, 'ff' => $ff, 'v' => $inherited['v'], 'da' => $da];
            foreach ($kidElements as $kidRef) {
                $kidObjNum = $kidRef instanceof PdfReference ? $kidRef->objectNumber : 0;
                $kidDict = $this->reader->resolve($kidRef);
                if (!$kidDict instanceof Dictionary) {
                    continue;
                }
                $this->walkNode($kidObjNum, $kidDict, $qualifiedName, $childInherited, $results, $depth + 1);
            }
        } else {
            // Terminal: kids are widget annotations only (no /T)
            // Collect the widget object numbers
            $widgetObjNums = [];
            foreach ($kidElements as $kidRef) {
                if ($kidRef instanceof PdfReference) {
                    $widgetObjNums[] = $kidRef->objectNumber;
                }
            }

            $resolved = $this->buildResolvedField($objNum, $dict, $widgetObjNums, $qualifiedName, $ft, $ff, $da);
            if ($resolved !== null) {
                $results[] = $resolved;
            }
        }
    }

    /**
     * @param list<int> $widgetObjectNumbers
     */
    private function buildResolvedField(
        int $objNum,
        Dictionary $dict,
        array $widgetObjectNumbers,
        string $name,
        ?string $ft,
        int $ff,
        ?string $da,
    ): ?ResolvedField {
        if ($ft === null) {
            return null;
        }

        $type = $this->resolveType($ft, $ff);
        $options = $this->resolveOptions($dict, $type, $widgetObjectNumbers);

        return new ResolvedField(
            objectNumber: $objNum,
            dict: $dict,
            widgetObjectNumbers: $widgetObjectNumbers,
            name: $name,
            type: $type,
            flags: $ff,
            defaultAppearance: $da,
            options: $options,
        );
    }

    private function resolveType(string $ft, int $ff): FormFieldType
    {
        return match ($ft) {
            'Tx' => FormFieldType::Text,
            'Sig' => FormFieldType::Signature,
            'Ch' => ($ff & 131072) !== 0 ? FormFieldType::Combobox : FormFieldType::Listbox,
            'Btn' => ($ff & 65536) !== 0
                ? FormFieldType::PushButton
                : (($ff & 32768) !== 0 ? FormFieldType::Radio : FormFieldType::Checkbox),
            default => FormFieldType::Text,
        };
    }

    /**
     * Resolves the options list for choice and radio fields.
     *
     * For Combobox/Listbox: reads /Opt array; each entry is either a text string
     * (export==display) or a 2-element array [export, display] -> use export.
     *
     * For Radio: reads the /AP /N dictionary key names of each widget kid,
     * excluding 'Off'.
     *
     * @param list<int> $widgetObjectNumbers
     * @return list<string>
     */
    private function resolveOptions(Dictionary $dict, FormFieldType $type, array $widgetObjectNumbers): array
    {
        if ($type === FormFieldType::Combobox || $type === FormFieldType::Listbox) {
            return $this->resolveOptArray($dict);
        }

        if ($type === FormFieldType::Radio) {
            return $this->resolveRadioOptions($widgetObjectNumbers);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function resolveOptArray(Dictionary $dict): array
    {
        $optRaw = $dict->get(Name::of('Opt'));
        if ($optRaw === null) {
            return [];
        }
        $opt = $this->reader->resolve($optRaw);
        if (!$opt instanceof PdfArray) {
            return [];
        }

        $options = [];
        foreach ($opt->elements() as $entry) {
            $resolved = $this->reader->resolve($entry);
            if ($resolved instanceof PdfArray) {
                // [export, display] pair: use export (first element)
                $elements = $resolved->elements();
                if (isset($elements[0])) {
                    $export = $this->extractString($this->reader->resolve($elements[0]));
                    if ($export !== null) {
                        $options[] = $export;
                    }
                }
            } else {
                // Plain text string = export==display
                $value = $this->extractString($resolved);
                if ($value !== null) {
                    $options[] = $value;
                }
            }
        }

        return $options;
    }

    /**
     * Collects radio option values from each widget's /AP /N dictionary keys,
     * excluding 'Off'.
     *
     * @param list<int> $widgetObjectNumbers
     * @return list<string>
     */
    private function resolveRadioOptions(array $widgetObjectNumbers): array
    {
        $options = [];
        foreach ($widgetObjectNumbers as $num) {
            $widgetDict = $this->reader->object($num);
            if (!$widgetDict instanceof Dictionary) {
                continue;
            }
            $apRaw = $widgetDict->get(Name::of('AP'));
            if ($apRaw === null) {
                continue;
            }
            $ap = $this->reader->resolve($apRaw);
            if (!$ap instanceof Dictionary) {
                continue;
            }
            $nRaw = $ap->get(Name::of('N'));
            if ($nRaw === null) {
                continue;
            }
            $n = $this->reader->resolve($nRaw);
            if (!$n instanceof Dictionary) {
                continue;
            }
            foreach ($n->entries() as [$key, $_value]) {
                $keyName = $key->value();
                if ($keyName !== 'Off' && !in_array($keyName, $options, true)) {
                    $options[] = $keyName;
                }
            }
        }

        return $options;
    }

    /**
     * Decodes a PDF text-string object (PdfString, TextString, or HexString
     * with optional UTF-16BE BOM) to a PHP string. Returns null for other types.
     */
    private function extractString(?PdfObject $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof TextString) {
            return $value->value();
        }
        if ($value instanceof PdfString) {
            return $value->value();
        }
        if ($value instanceof HexString) {
            $binary = hex2bin($value->hex());
            if ($binary === false) {
                return null;
            }
            if (str_starts_with($binary, "\xFE\xFF")) {
                return mb_convert_encoding(substr($binary, 2), 'UTF-8', 'UTF-16BE');
            }
            return $binary;
        }
        return null;
    }
}
