<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * Applies a stream's /Filter chain with per-filter /DecodeParms. Supports
 * the structural filters only (FlateDecode, ASCIIHexDecode, ASCII85Decode,
 * RunLengthDecode); image filters (DCT/JPX/CCITT/JBIG2) and LZW are not
 * decoded by the reader and raise a PdfParseException.
 *
 * @internal
 */
final readonly class StreamDecoder
{
    /**
     * @param \Closure(PdfObject): PdfObject $resolve resolves indirect references
     */
    public function decode(ReadStream $stream, \Closure $resolve): string
    {
        $filters = $this->filterNames($stream->dict, $resolve);
        $parmsList = $this->decodeParms($stream->dict, $resolve);
        $data = $stream->rawData();
        foreach ($filters as $index => $filter) {
            $parms = $parmsList[$index] ?? null;
            $data = match ($filter) {
                'FlateDecode', 'Fl' => FlateDecoder::decode(
                    $data,
                    $this->intParm($parms, 'Predictor', 1, $resolve),
                    $this->intParm($parms, 'Colors', 1, $resolve),
                    $this->intParm($parms, 'BitsPerComponent', 8, $resolve),
                    $this->intParm($parms, 'Columns', 1, $resolve),
                ),
                'ASCIIHexDecode', 'AHx' => AsciiHexDecoder::decode($data),
                'ASCII85Decode', 'A85' => Ascii85Decoder::decode($data),
                'RunLengthDecode', 'RL' => RunLengthDecoder::decode($data),
                default => throw new PdfParseException("Unsupported stream filter /{$filter}"),
            };
        }
        return $data;
    }

    /**
     * @param \Closure(PdfObject): PdfObject $resolve
     * @return list<string>
     */
    private function filterNames(Dictionary $dict, \Closure $resolve): array
    {
        $filter = $dict->get(Name::of('Filter'));
        if ($filter === null) {
            return [];
        }
        $filter = $resolve($filter);
        if ($filter instanceof Name) {
            return [$filter->value()];
        }
        if ($filter instanceof PdfArray) {
            $names = [];
            foreach ($filter->elements() as $element) {
                $element = $resolve($element);
                if (!$element instanceof Name) {
                    throw new PdfParseException('Filter array contains a non-name entry');
                }
                $names[] = $element->value();
            }
            return $names;
        }
        throw new PdfParseException('Invalid /Filter entry: expected a name or an array of names');
    }

    /**
     * @param \Closure(PdfObject): PdfObject $resolve
     * @return list<?Dictionary>
     */
    private function decodeParms(Dictionary $dict, \Closure $resolve): array
    {
        $parms = $dict->get(Name::of('DecodeParms')) ?? $dict->get(Name::of('DP'));
        if ($parms === null) {
            return [];
        }
        $parms = $resolve($parms);
        if ($parms instanceof Dictionary) {
            return [$parms];
        }
        if ($parms instanceof PdfArray) {
            $list = [];
            foreach ($parms->elements() as $element) {
                $element = $resolve($element);
                $list[] = $element instanceof Dictionary ? $element : null;
            }
            return $list;
        }
        return [];
    }

    /**
     * @param \Closure(PdfObject): PdfObject $resolve
     */
    private function intParm(?Dictionary $parms, string $key, int $default, \Closure $resolve): int
    {
        if ($parms === null) {
            return $default;
        }
        $value = $parms->get(Name::of($key));
        if ($value === null) {
            return $default;
        }
        $value = $resolve($value);
        if (!$value instanceof PdfNumber) {
            return $default;
        }
        $number = $value->value();
        return is_int($number) ? $number : (int) $number;
    }
}
