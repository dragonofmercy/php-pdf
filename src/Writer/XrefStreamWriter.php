<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;

/**
 * Appends an incremental revision whose cross-reference is a STREAM
 * (ISO 32000-1 7.5.8) - mandatory when the prior revision uses one. The
 * xref stream object takes object number $size and lists itself; /W is
 * [1 4 2] (type, 4-byte offset, 2-byte generation); trailer keys live in
 * the stream dictionary.
 *
 * @internal
 */
final readonly class XrefStreamWriter
{
    private const int W_TYPE = 1;
    private const int W_OFFSET = 4;
    private const int W_GENERATION = 2;

    /**
     * @param list<IndirectObject> $newObjects
     * @param Dictionary $trailerEntries /Root (+ optional /Info, /ID) - /Size and /Prev are added here
     */
    public function append(
        string $priorBytes,
        array $newObjects,
        Dictionary $trailerEntries,
        int $prevStartxref,
        int $size,
    ): string {
        if ($newObjects === []) {
            throw new PdfException('An incremental revision needs at least one object');
        }
        $bytes = $priorBytes;
        $offsets = [];
        foreach ($newObjects as $object) {
            $offsets[$object->objectNumber] = strlen($bytes);
            $bytes .= $object->toBytes();
        }
        $xrefStreamNumber = $size;
        $offsets[$xrefStreamNumber] = strlen($bytes);

        ksort($offsets);
        $rows = '';
        $index = [];
        $previousNumber = null;
        foreach ($offsets as $number => $offset) {
            if ($previousNumber === null || $number !== $previousNumber + 1) {
                $index[] = [$number, 0];
            }
            $index[count($index) - 1][1]++;
            $previousNumber = $number;
            $rows .= chr(1)
                . pack('N', $offset)
                . pack('n', 0);
        }
        $payload = gzcompress($rows, 9);
        if ($payload === false) {
            throw new PdfException('Failed to compress the cross-reference stream');
        }

        $indexNumbers = [];
        foreach ($index as [$start, $count]) {
            $indexNumbers[] = PdfNumber::ofInt($start);
            $indexNumbers[] = PdfNumber::ofInt($count);
        }
        $dict = Dictionary::empty()
            ->withEntry(Name::of('Type'), Name::of('XRef'))
            ->withEntry(Name::of('Size'), PdfNumber::ofInt($size + 1))
            ->withEntry(Name::of('W'), PdfArray::of(
                PdfNumber::ofInt(self::W_TYPE),
                PdfNumber::ofInt(self::W_OFFSET),
                PdfNumber::ofInt(self::W_GENERATION),
            ))
            ->withEntry(Name::of('Index'), PdfArray::of(...$indexNumbers))
            ->withEntry(Name::of('Prev'), PdfNumber::ofInt($prevStartxref))
            ->withEntry(Name::of('Filter'), Name::of('FlateDecode'));
        foreach ($trailerEntries->entries() as [$name, $value]) {
            $dict = $dict->withEntry($name, $value);
        }

        $bytes .= $xrefStreamNumber . " 0 obj\n"
            . $dict->withEntry(Name::of('Length'), PdfNumber::ofInt(strlen($payload)))->toBytes()
            . "\nstream\n" . $payload . "\nendstream\nendobj\n";
        $bytes .= "startxref\n" . $offsets[$xrefStreamNumber] . "\n%%EOF\n";
        return $bytes;
    }
}
