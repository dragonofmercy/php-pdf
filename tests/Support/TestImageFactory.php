<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Support;

/**
 * Builds minimal valid JPEG / PNG byte sequences in memory for parser and
 * embedder tests. Some samples use GD; others are constructed manually
 * (pack/zlib/crc32) to cover formats GD cannot emit.
 *
 * Outputs are NOT optimized for size and are NOT guaranteed to render
 * pixel-perfect -- they are valid for parsing and PDF embedding.
 *
 * @internal test helper
 */
final class TestImageFactory
{
    // ----- JPEG: stub markers (parser-friendly, not decodable) -----

    /** Stub baseline JPEG: SOI + SOF0 (Nf=3, RGB) + EOI. width/height settable. */
    public static function stubJpegRgb(int $width = 2, int $height = 2): string
    {
        return self::stubJpeg($width, $height, components: 3);
    }

    /** Stub baseline JPEG: SOI + SOF0 (Nf=1, Gray) + EOI. */
    public static function stubJpegGray(int $width = 2, int $height = 2): string
    {
        return self::stubJpeg($width, $height, components: 1);
    }

    /** Stub baseline JPEG: SOI + SOF0 (Nf=4, CMYK) + EOI. */
    public static function stubJpegCmyk(int $width = 2, int $height = 2): string
    {
        return self::stubJpeg($width, $height, components: 4);
    }

    /** Stub progressive JPEG: SOI + SOF2 (Nf=3, RGB) + EOI. */
    public static function stubJpegProgressive(int $width = 2, int $height = 2): string
    {
        $sof = chr(0xFF) . chr(0xC2) . self::sofPayload($width, $height, components: 3);
        return "\xFF\xD8" . $sof . "\xFF\xD9";
    }

    /** Stub JPEG with APP0 (JFIF) and a COM segment before SOF0. */
    public static function stubJpegRgbWithApp(int $width = 2, int $height = 2): string
    {
        // APP0 (FFE0) length=16, JFIF\0 + version + units + density + thumb
        $app0 = "\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x02\x00\x00\x48\x00\x48\x00\x00";
        // COM (FFFE) length=8, "phppdf"
        $com = "\xFF\xFE" . pack('n', 8) . 'phppdf';
        $sof = chr(0xFF) . chr(0xC0) . self::sofPayload($width, $height, components: 3);
        return "\xFF\xD8" . $app0 . $com . $sof . "\xFF\xD9";
    }

    private static function stubJpeg(int $width, int $height, int $components): string
    {
        $sof = chr(0xFF) . chr(0xC0) . self::sofPayload($width, $height, $components);
        return "\xFF\xD8" . $sof . "\xFF\xD9";
    }

    private static function sofPayload(int $width, int $height, int $components): string
    {
        // length = 2 (length field) + 1 (P) + 2 (Y) + 2 (X) + 1 (Nf) + 3*Nf
        $length = 8 + 3 * $components;
        $payload = pack('n', $length)
            . chr(8)
            . pack('n', $height)
            . pack('n', $width)
            . pack('C', $components);
        for ($i = 1; $i <= $components; $i++) {
            // component id, sampling factors (0x11 = no subsampling), qtable id 0
            $payload .= pack('C', $i) . chr(0x11) . chr(0);
        }
        return $payload;
    }

    // ----- PNG: chunk-builder + canned variants -----

    public const string PNG_SIGNATURE = "\x89PNG\r\n\x1A\n";

    /** PNG with IHDR + 1 IDAT + IEND. RGB, 2x2, all white. */
    public static function pngRgb(int $width = 2, int $height = 2): string
    {
        // 1 filter byte (0=None) per row + 3 RGB bytes per pixel, all 0xFF (white).
        $row = "\x00" . str_repeat("\xFF", 3 * $width);
        $raw = str_repeat($row, $height);
        return self::PNG_SIGNATURE
            . self::chunk('IHDR', self::ihdr($width, $height, bitDepth: 8, colorType: 2))
            . self::chunk('IDAT', self::compress($raw))
            . self::chunk('IEND', '');
    }

    /** PNG grayscale (color type 0), 2x2, all 0x80. */
    public static function pngGray(int $width = 2, int $height = 2): string
    {
        $row = "\x00" . str_repeat("\x80", $width);
        $raw = str_repeat($row, $height);
        return self::PNG_SIGNATURE
            . self::chunk('IHDR', self::ihdr($width, $height, bitDepth: 8, colorType: 0))
            . self::chunk('IDAT', self::compress($raw))
            . self::chunk('IEND', '');
    }

    /** PNG palette (color type 3), 2x2, palette of 2 colors, all index 0. */
    public static function pngPalette(int $width = 2, int $height = 2): string
    {
        $row = "\x00" . str_repeat("\x00", $width);   // each pixel is index 0
        $raw = str_repeat($row, $height);
        $palette = "\xFF\x00\x00" . "\x00\xFF\x00";   // red, green
        return self::PNG_SIGNATURE
            . self::chunk('IHDR', self::ihdr($width, $height, bitDepth: 8, colorType: 3))
            . self::chunk('PLTE', $palette)
            . self::chunk('IDAT', self::compress($raw))
            . self::chunk('IEND', '');
    }

    /** PNG palette + tRNS: 2-color palette, first index transparent. */
    public static function pngPaletteWithTrns(int $width = 2, int $height = 2): string
    {
        $row = "\x00" . str_repeat("\x00", $width);
        $raw = str_repeat($row, $height);
        $palette = "\xFF\x00\x00" . "\x00\xFF\x00";
        $trns = "\x00\xFF";   // index 0 = fully transparent, index 1 = fully opaque
        return self::PNG_SIGNATURE
            . self::chunk('IHDR', self::ihdr($width, $height, bitDepth: 8, colorType: 3))
            . self::chunk('PLTE', $palette)
            . self::chunk('tRNS', $trns)
            . self::chunk('IDAT', self::compress($raw))
            . self::chunk('IEND', '');
    }

    /** PNG RGB+Alpha (color type 6), 2x2, opaque red. */
    public static function pngRgbAlpha(int $width = 2, int $height = 2): string
    {
        $row = "\x00" . str_repeat("\xFF\x00\x00\xFF", $width);   // R=255 G=0 B=0 A=255
        $raw = str_repeat($row, $height);
        return self::PNG_SIGNATURE
            . self::chunk('IHDR', self::ihdr($width, $height, bitDepth: 8, colorType: 6))
            . self::chunk('IDAT', self::compress($raw))
            . self::chunk('IEND', '');
    }

    /** PNG Gray+Alpha (color type 4), 2x2, mid-gray semi-transparent. */
    public static function pngGrayAlpha(int $width = 2, int $height = 2): string
    {
        $row = "\x00" . str_repeat("\x80\x80", $width);   // gray=128, alpha=128
        $raw = str_repeat($row, $height);
        return self::PNG_SIGNATURE
            . self::chunk('IHDR', self::ihdr($width, $height, bitDepth: 8, colorType: 4))
            . self::chunk('IDAT', self::compress($raw))
            . self::chunk('IEND', '');
    }

    /** PNG with IDAT chunk split into N pieces (tests multi-IDAT concat). */
    public static function pngRgbMultiIdat(int $width = 2, int $height = 2, int $pieces = 3): string
    {
        $row = "\x00" . str_repeat("\xFF", 3 * $width);
        $raw = str_repeat($row, $height);
        $idat = self::compress($raw);
        $len = strlen($idat);
        $chunkSize = (int) ceil($len / $pieces);
        $parts = '';
        for ($offset = 0; $offset < $len; $offset += $chunkSize) {
            $parts .= self::chunk('IDAT', substr($idat, $offset, $chunkSize));
        }
        return self::PNG_SIGNATURE
            . self::chunk('IHDR', self::ihdr($width, $height, bitDepth: 8, colorType: 2))
            . $parts
            . self::chunk('IEND', '');
    }

    /** PNG with bitDepth=16 (parser must reject). */
    public static function pngRgb16Bit(): string
    {
        // We do not need real pixel data for a rejection test; IHDR alone triggers the throw.
        return self::PNG_SIGNATURE
            . self::chunk('IHDR', self::ihdr(2, 2, bitDepth: 16, colorType: 2))
            . self::chunk('IEND', '');
    }

    /** PNG with interlaceMethod=1 (Adam7) -- parser must reject. */
    public static function pngRgbAdam7(): string
    {
        return self::PNG_SIGNATURE
            . self::chunk('IHDR', self::ihdr(2, 2, bitDepth: 8, colorType: 2, interlace: 1))
            . self::chunk('IEND', '');
    }

    private static function ihdr(int $width, int $height, int $bitDepth, int $colorType, int $interlace = 0): string
    {
        // PNG IHDR: width(4 BE) + height(4 BE) + bitDepth(1) + colorType(1) + compMethod(0) + filterMethod(0) + interlace(1) = 13 bytes
        return pack('NNCCxxC', $width, $height, $bitDepth, $colorType, $interlace);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function compress(string $raw): string
    {
        $compressed = gzcompress($raw, 6);
        if ($compressed === false) {
            throw new \RuntimeException('gzcompress failed in TestImageFactory');
        }
        return $compressed;
    }
}
