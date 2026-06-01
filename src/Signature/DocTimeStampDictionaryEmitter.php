<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\RawValue;

/**
 * Builds the /DocTimeStamp value dictionary (PAdES document timestamp,
 * SubFilter ETSI.RFC3161) with fixed-width /ByteRange and /Contents
 * placeholders that DocTimeStampPatcher overwrites. Pure: no openssl, no TSA
 * call here.
 */
final readonly class DocTimeStampDictionaryEmitter
{
    public function emit(int $maxSignatureBytes, int $objectNumber): IndirectObject
    {
        $contents = '<' . str_repeat('0', $maxSignatureBytes * 2) . '>';

        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('DocTimeStamp'))
            ->withEntry(Name::of('Filter'), Name::of('Adobe.PPKLite'))
            ->withEntry(Name::of('SubFilter'), Name::of('ETSI.RFC3161'))
            ->withEntry(Name::of('ByteRange'), RawValue::of(SignatureDictionaryEmitter::BYTERANGE_PLACEHOLDER))
            ->withEntry(Name::of('Contents'), RawValue::of($contents));

        return IndirectObject::of($objectNumber, 0, $dict);
    }
}
