<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

use DragonOfMercy\PhpPdf\Reader\DictReader;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;

/**
 * Decodes a resolved field's merged /V entry into its PHP-native value.
 * Shared by PdfEditor (introspection) and EditRevisionBuilder (flatten):
 *
 * - Text / Combobox        : string|null  (decoded TextString/PdfString/HexString)
 * - Checkbox               : bool         (true iff /V is a Name != 'Off')
 * - Radio                  : string|null  (export name, null when absent or 'Off')
 * - Listbox                : string|list<string>|null
 * - PushButton / Signature : null
 */
final class FieldValueDecoder
{
    /**
     * @return string|bool|list<string>|null
     */
    public static function decode(ResolvedField $rf, PdfReader $reader): string|bool|array|null
    {
        $raw = $rf->dict->get(Name::of('V'));
        $resolved = $raw !== null ? $reader->resolve($raw) : null;

        if ($rf->type === FormFieldType::Text || $rf->type === FormFieldType::Combobox) {
            return DictReader::decodeText($resolved);
        }

        if ($rf->type === FormFieldType::Checkbox) {
            return $resolved instanceof Name && $resolved->value() !== 'Off';
        }

        if ($rf->type === FormFieldType::Radio) {
            if (!$resolved instanceof Name) {
                return null;
            }
            $exportName = $resolved->value();
            return $exportName !== 'Off' ? $exportName : null;
        }

        if ($rf->type === FormFieldType::Listbox) {
            if ($resolved instanceof PdfArray) {
                /** @var list<string> $items */
                $items = [];
                foreach ($resolved->elements() as $element) {
                    $text = DictReader::decodeText($reader->resolve($element));
                    if ($text !== null) {
                        $items[] = $text;
                    }
                }
                return $items !== [] ? $items : null;
            }
            return DictReader::decodeText($resolved);
        }

        // PushButton, Signature
        return null;
    }
}
