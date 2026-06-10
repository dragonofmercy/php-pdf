<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify;

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
     * @param Dictionary $trailerEntries /Root (+ optional /Info, /ID)
     * @param int $size object count BEFORE this revision's xref object (= next free number)
     */
    public function append(
        PdfReader $reader,
        string $priorBytes,
        array $newObjects,
        Dictionary $trailerEntries,
        int $size,
    ): string {
        if ($reader->usesXrefStreams()) {
            return (new XrefStreamWriter())->append($priorBytes, $newObjects, $trailerEntries, $reader->lastStartxref(), $size);
        }
        return $this->appendClassic($priorBytes, $newObjects, $trailerEntries, $reader->lastStartxref(), $size);
    }

    /** @param list<IndirectObject> $newObjects */
    private function appendClassic(
        string $priorBytes,
        array $newObjects,
        Dictionary $trailerEntries,
        int $prevStartxref,
        int $size,
    ): string {
        $bytes = $priorBytes;
        $xref = new IncrementalXref();
        foreach ($newObjects as $object) {
            $xref->recordOffset($object->objectNumber, strlen($bytes));
            $bytes .= $object->toBytes();
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
