<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

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
