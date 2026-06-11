<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\TextString;

/**
 * Typed extraction of Dictionary entries with optional reference resolution.
 * Returns null on absent keys or type mismatches; callers decide whether
 * that is fatal.
 *
 * @internal
 */
final readonly class DictReader
{
    /** @param ?\Closure(PdfObject): PdfObject $resolve */
    public static function int(Dictionary $dict, string $key, ?\Closure $resolve = null): ?int
    {
        $value = self::entry($dict, $key, $resolve);
        if (!$value instanceof PdfNumber) {
            return null;
        }
        $number = $value->value();
        return is_int($number) ? $number : (int) $number;
    }

    /** @param ?\Closure(PdfObject): PdfObject $resolve */
    public static function name(Dictionary $dict, string $key, ?\Closure $resolve = null): ?string
    {
        $value = self::entry($dict, $key, $resolve);
        return $value instanceof Name ? $value->value() : null;
    }

    /**
     * @param ?\Closure(PdfObject): PdfObject $resolve
     * @return ?list<int>
     */
    public static function intList(Dictionary $dict, string $key, ?\Closure $resolve = null): ?array
    {
        $value = self::entry($dict, $key, $resolve);
        if (!$value instanceof PdfArray) {
            return null;
        }
        $ints = [];
        foreach ($value->elements() as $element) {
            if ($resolve !== null) {
                $element = $resolve($element);
            }
            if (!$element instanceof PdfNumber) {
                return null;
            }
            $number = $element->value();
            $ints[] = is_int($number) ? $number : (int) $number;
        }
        return $ints;
    }

    /** @param ?\Closure(PdfObject): PdfObject $resolve */
    public static function dictionary(Dictionary $dict, string $key, ?\Closure $resolve = null): ?Dictionary
    {
        $value = self::entry($dict, $key, $resolve);
        return $value instanceof Dictionary ? $value : null;
    }

    /**
     * Decodes a PDF text-string object (TextString, PdfString, or HexString
     * with optional UTF-16BE BOM \xFE\xFF) to a PHP UTF-8 string.
     * Returns null for a null input, an invalid hex payload, or any other type.
     */
    public static function decodeText(?PdfObject $value): ?string
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

    /** @param ?\Closure(PdfObject): PdfObject $resolve */
    private static function entry(Dictionary $dict, string $key, ?\Closure $resolve): ?PdfObject
    {
        $value = $dict->get(Name::of($key));
        if ($value === null) {
            return null;
        }
        return $resolve !== null ? $resolve($value) : $value;
    }
}
