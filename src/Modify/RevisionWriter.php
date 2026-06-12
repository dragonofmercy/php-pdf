<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify;

use DragonOfMercy\PhpPdf\Encryption\Reader\IncrementalObjectEncryptor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\IncrementalXref;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\XrefStreamWriter;

/**
 * Appends one incremental revision to an opened PDF, matching the source's
 * cross-reference format: classic table for classic sources, cross-reference
 * stream for stream sources (ISO 32000-1 7.5.8 requires the formats to match).
 *
 * @internal
 */
final readonly class RevisionWriter
{
    /**
     * @param list<IndirectObject> $newObjects
     * @param Dictionary $trailerEntries /Root (+ optional /Info, /ID, /Encrypt)
     * @param int $size object count BEFORE this revision's xref object (= next free number)
     * @param ?IncrementalObjectEncryptor $encryptor re-encrypts each new object (encrypted source); null = byte-identical plain path
     */
    public function append(
        PdfReader $reader,
        string $priorBytes,
        array $newObjects,
        Dictionary $trailerEntries,
        int $size,
        ?IncrementalObjectEncryptor $encryptor = null,
    ): string {
        if ($reader->usesXrefStreams()) {
            return (new XrefStreamWriter())->append($priorBytes, $newObjects, $trailerEntries, $reader->lastStartxref(), $size, $encryptor);
        }
        return $this->appendClassic($priorBytes, $newObjects, $trailerEntries, $reader->lastStartxref(), $size, $encryptor);
    }

    /** @param list<IndirectObject> $newObjects */
    private function appendClassic(
        string $priorBytes,
        array $newObjects,
        Dictionary $trailerEntries,
        int $prevStartxref,
        int $size,
        ?IncrementalObjectEncryptor $encryptor,
    ): string {
        $bytes = $priorBytes;
        $xref = new IncrementalXref();
        foreach ($newObjects as $object) {
            // The xref offset is recorded for the object's number, which encryption
            // leaves unchanged; only the string/stream bytes are re-encrypted.
            $emit = $encryptor !== null ? $encryptor->encrypt($object) : $object;
            $xref->recordOffset($object->objectNumber, strlen($bytes));
            $bytes .= $emit->toBytes();
        }
        $xrefOffset = strlen($bytes);
        $bytes .= $xref->toBytes();

        $dict = $trailerEntries
            ->withEntry(Name::of('Size'), PdfNumber::ofInt($size))
            ->withEntry(Name::of('Prev'), PdfNumber::ofInt($prevStartxref));
        $bytes .= "trailer\n" . $dict->toBytes() . "\nstartxref\n" . $xrefOffset . "\n%%EOF\n";
        return $bytes;
    }
}
