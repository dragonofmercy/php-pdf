<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;

/**
 * Reverses TIFF predictor 2 and PNG predictors 10-15 (PDF 1.7 7.4.4.4).
 * Used by FlateDecode/LZWDecode parameters; xref streams in the wild almost
 * always use PNG Up (12).
 *
 * @internal
 */
final readonly class PredictorDecoder
{
    public static function apply(string $data, int $predictor, int $colors, int $bitsPerComponent, int $columns): string
    {
        if ($predictor === 2) {
            return self::applyTiff($data, $colors, $bitsPerComponent, $columns);
        }
        if ($predictor >= 10 && $predictor <= 15) {
            return self::applyPng($data, $colors, $bitsPerComponent, $columns);
        }
        throw new PdfParseException("Unsupported predictor {$predictor}");
    }

    private static function applyTiff(string $data, int $colors, int $bitsPerComponent, int $columns): string
    {
        if ($bitsPerComponent !== 8) {
            throw new PdfParseException("TIFF predictor supports only 8 bits per component, got {$bitsPerComponent}");
        }
        $rowLength = $colors * $columns;
        $out = '';
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $rowBytes = self::unpackBytes(substr($data, $offset, $rowLength), $offset);
            $count = count($rowBytes);
            for ($i = $colors; $i < $count; $i++) {
                $rowBytes[$i] = ($rowBytes[$i] + $rowBytes[$i - $colors]) & 0xFF;
            }
            $out .= pack('C*', ...$rowBytes);
            $offset += $rowLength;
        }
        return $out;
    }

    private static function applyPng(string $data, int $colors, int $bitsPerComponent, int $columns): string
    {
        $bytesPerPixel = max(1, intdiv($colors * $bitsPerComponent, 8));
        $rowLength = (int) ceil($colors * $bitsPerComponent * $columns / 8);
        $out = '';
        $previous = array_fill(0, $rowLength, 0);
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            if ($length - $offset < 1 + $rowLength) {
                throw new PdfParseException(sprintf(
                    'Truncated predictor row at offset %d: need %d bytes, have %d',
                    $offset,
                    1 + $rowLength,
                    $length - $offset,
                ));
            }
            $filter = ord($data[$offset]);
            $row = self::unpackBytes(substr($data, $offset + 1, $rowLength), $offset);
            for ($i = 0; $i < $rowLength; $i++) {
                $left = $i >= $bytesPerPixel ? $row[$i - $bytesPerPixel] : 0;
                $up = $previous[$i];
                $upLeft = $i >= $bytesPerPixel ? $previous[$i - $bytesPerPixel] : 0;
                $row[$i] = ($row[$i] + match ($filter) {
                    0 => 0,
                    1 => $left,
                    2 => $up,
                    3 => intdiv($left + $up, 2),
                    4 => self::paeth($left, $up, $upLeft),
                    default => throw new PdfParseException("Unknown PNG predictor filter byte {$filter} at offset {$offset}"),
                }) & 0xFF;
            }
            $out .= pack('C*', ...$row);
            $previous = $row;
            $offset += 1 + $rowLength;
        }
        return $out;
    }

    private static function paeth(int $left, int $up, int $upLeft): int
    {
        $p = $left + $up - $upLeft;
        $pa = abs($p - $left);
        $pb = abs($p - $up);
        $pc = abs($p - $upLeft);
        if ($pa <= $pb && $pa <= $pc) {
            return $left;
        }
        return $pb <= $pc ? $up : $upLeft;
    }

    /**
     * Unpack a byte string into an array of unsigned integers.
     * The 'C*' format always produces int values; each element is validated
     * with is_int() so that PHPStan can infer int[] instead of mixed[].
     *
     * @return int[]
     */
    private static function unpackBytes(string $bytes, int $offsetForError): array
    {
        $unpacked = unpack('C*', $bytes);
        if ($unpacked === false) {
            throw new PdfParseException("Failed to unpack bytes at offset {$offsetForError}");
        }
        $result = [];
        foreach (array_values($unpacked) as $b) {
            if (!is_int($b)) {
                throw new PdfParseException("Unexpected non-integer byte value at offset {$offsetForError}");
            }
            $result[] = $b;
        }
        return $result;
    }
}
