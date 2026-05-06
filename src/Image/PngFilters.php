<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Image;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Reconstructs raw scanline bytes from PNG-filtered scanlines. Each input
 * row begins with a 1-byte filter type followed by `width * bpp` filtered
 * bytes. Output is `height * width * bpp` raw bytes (no filter prefixes).
 *
 * @internal
 */
final class PngFilters
{
    public static function unfilter(string $filtered, int $width, int $height, int $bpp): string
    {
        $rowDataLen = $width * $bpp;
        $expected = $height * (1 + $rowDataLen);
        if (strlen($filtered) < $expected) {
            throw new PdfException('PNG filtered stream is truncated');
        }

        $raw = '';
        $prevRow = str_repeat("\x00", $rowDataLen);
        $offset = 0;
        for ($y = 0; $y < $height; $y++) {
            $filterType = ord($filtered[$offset]);
            $offset++;
            $rowFiltered = substr($filtered, $offset, $rowDataLen);
            $offset += $rowDataLen;

            $current = match ($filterType) {
                0 => $rowFiltered,
                1 => self::reverseSub($rowFiltered, $bpp),
                2 => self::reverseUp($rowFiltered, $prevRow),
                3 => self::reverseAverage($rowFiltered, $prevRow, $bpp),
                4 => self::reversePaeth($rowFiltered, $prevRow, $bpp),
                default => throw new PdfException("Unknown PNG filter type: {$filterType}"),
            };

            $raw .= $current;
            $prevRow = $current;
        }

        return $raw;
    }

    private static function reverseSub(string $row, int $bpp): string
    {
        $len = strlen($row);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $out .= self::byte(ord($row[$i]) + $a);
        }
        return $out;
    }

    private static function reverseUp(string $row, string $prev): string
    {
        $len = strlen($row);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $b = ord($prev[$i]);
            $out .= self::byte(ord($row[$i]) + $b);
        }
        return $out;
    }

    private static function reverseAverage(string $row, string $prev, int $bpp): string
    {
        $len = strlen($row);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            $out .= self::byte(ord($row[$i]) + intdiv($a + $b, 2));
        }
        return $out;
    }

    private static function reversePaeth(string $row, string $prev, int $bpp): string
    {
        $len = strlen($row);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
            $out .= self::byte(ord($row[$i]) + self::paeth($a, $b, $c));
        }
        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }
        return $c;
    }

    /**
     * Returns a single-byte string from an integer, masking to 8 bits.
     * The explicit mask ensures the value is int<0, 255> for PHPStan.
     */
    private static function byte(int $value): string
    {
        return chr($value & 0xFF);
    }
}
