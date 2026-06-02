<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use DragonOfMercy\PhpPdf\Writer\Object\RawValue;
use DragonOfMercy\PhpPdf\Writer\PdfDate;

/**
 * Builds the /Sig dictionary indirect object with fixed-width /ByteRange and
 * /Contents placeholders that SignaturePatcher overwrites in the final bytes.
 * Pure: no openssl, no signing performed here.
 */
final readonly class SignatureDictionaryEmitter
{
    public const string BYTERANGE_PLACEHOLDER = '[0 0000000000 0000000000 0000000000]';

    public function emit(Signature $sig, int $objectNumber): IndirectObject
    {
        $contents = '<' . str_repeat('0', $sig->maxSignatureBytes * 2) . '>';

        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('Sig'))
            ->withEntry(Name::of('Filter'), Name::of('Adobe.PPKLite'))
            ->withEntry(Name::of('SubFilter'), Name::of($sig->format->subFilter()))
            ->withEntry(Name::of('ByteRange'), RawValue::of(self::BYTERANGE_PLACEHOLDER))
            ->withEntry(Name::of('Contents'), RawValue::of($contents))
            ->withEntry(Name::of('M'), PdfString::of(PdfDate::format($sig->signedAt)));

        if ($sig->reason !== null) {
            $dict = $dict->withEntry(Name::of('Reason'), PdfString::of($sig->reason));
        }
        if ($sig->location !== null) {
            $dict = $dict->withEntry(Name::of('Location'), PdfString::of($sig->location));
        }
        if ($sig->contactInfo !== null) {
            $dict = $dict->withEntry(Name::of('ContactInfo'), PdfString::of($sig->contactInfo));
        }

        return IndirectObject::of($objectNumber, 0, $dict);
    }
}
