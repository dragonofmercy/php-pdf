<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;

/**
 * Resolves a PDF destination to the object number of the page it points at, or
 * null when the destination does not directly name a page object.
 *
 * Handled: an explicit destination array [pageRef /Fit ...] (the common
 * intra-document form), a reference to such an array, and a GoTo action dict
 * << /S /GoTo /D [...] >>. NOT handled (returns null): a /D that is a name or
 * string (named-destination indirection), an integer-page-number first element
 * (remote /GoToR form), and non-GoTo actions (e.g. /URI).
 *
 * @internal
 */
final readonly class DestinationTarget
{
    public static function pageObjectNumber(PdfObject $value, PdfReader $reader): ?int
    {
        $value = $reader->resolve($value);

        if ($value instanceof Dictionary) {
            $s = $value->get(Name::of('S'));
            if (!$s instanceof Name || $s->value() !== 'GoTo') {
                return null;
            }
            $d = $value->get(Name::of('D'));
            if ($d === null) {
                return null;
            }
            return self::fromArray($reader->resolve($d));
        }

        return self::fromArray($value);
    }

    private static function fromArray(PdfObject $value): ?int
    {
        if (!$value instanceof PdfArray) {
            return null;
        }
        $first = $value->elements()[0] ?? null;
        return $first instanceof PdfReference ? $first->objectNumber : null;
    }
}
