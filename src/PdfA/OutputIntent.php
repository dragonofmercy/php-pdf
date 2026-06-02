<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\PdfA;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;

/**
 * Builds the PDF/A /OutputIntent dictionary and its embedded ICC profile
 * stream. The intent declares device-independent colour for an sRGB output
 * condition; the profile stream carries the FlateDecode-compressed ICC bytes.
 *
 * @internal
 */
final class OutputIntent
{
    private const string CONDITION = 'sRGB IEC61966-2.1';

    /**
     * @return array{0: IndirectObject, 1: IndirectObject} the intent object then the profile object
     */
    public function build(int $intentObjectNumber, int $profileObjectNumber, string $iccBytes): array
    {
        $compressed = gzcompress($iccBytes, 9);
        if ($compressed === false) {
            throw new PdfException('Failed to compress the ICC profile for the PDF/A output intent');
        }

        $intentDict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('OutputIntent'))
            ->withEntry(Name::of('S'), Name::of('GTS_PDFA1'))
            ->withEntry(Name::of('OutputConditionIdentifier'), PdfString::of(self::CONDITION))
            ->withEntry(Name::of('Info'), PdfString::of(self::CONDITION))
            ->withEntry(Name::of('DestOutputProfile'), PdfReference::to($profileObjectNumber, 0));

        $intent = IndirectObject::of($intentObjectNumber, 0, $intentDict);
        $profile = IndirectObject::of($profileObjectNumber, 0, new IccProfileStream($compressed));

        return [$intent, $profile];
    }
}
