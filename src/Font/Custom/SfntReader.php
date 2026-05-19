<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Pure big-endian sfnt readers shared by GlyphClosure and TtfSubsetter.
 * Self-contained: does not depend on TtfParser (whose golden output must
 * not drift). Assumes already-validated TTF bytes (registration parsed them).
 *
 * @internal
 */
final class SfntReader
{
    public static function u16(string $bytes, int $offset): int
    {
        $u = unpack('nv', substr($bytes, $offset, 2));
        if ($u === false || !is_int($u['v'])) {
            throw new PdfException("Cannot read uint16 at offset {$offset}");
        }
        return $u['v'];
    }

    public static function i16(string $bytes, int $offset): int
    {
        $v = self::u16($bytes, $offset);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    public static function u32(string $bytes, int $offset): int
    {
        $u = unpack('Nv', substr($bytes, $offset, 4));
        if ($u === false || !is_int($u['v'])) {
            throw new PdfException("Cannot read uint32 at offset {$offset}");
        }
        return $u['v'];
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    public static function directory(string $bytes, string $ctx): array
    {
        $numTables = self::u16($bytes, 4);
        if (strlen($bytes) < 12 + $numTables * 16) {
            throw new PdfException("sfnt table directory truncated in {$ctx}");
        }
        $dir = [];
        for ($i = 0; $i < $numTables; $i++) {
            $rec = 12 + $i * 16;
            $tag = substr($bytes, $rec, 4);
            $dir[$tag] = [
                'offset' => self::u32($bytes, $rec + 8),
                'length' => self::u32($bytes, $rec + 12),
            ];
        }
        if ($dir === []) {
            throw new PdfException("Empty sfnt table directory in {$ctx}");
        }
        return $dir;
    }

    /**
     * Builds the numGlyphs+1 glyph offsets. Short format stores uint16
     * half-offsets (multiply by 2); long format stores uint32 offsets.
     *
     * @return list<int>
     */
    public static function loca(string $locaBytes, int $base, int $indexToLocFormat, int $numGlyphs): array
    {
        $offsets = [];
        if ($indexToLocFormat === 0) {
            for ($i = 0; $i <= $numGlyphs; $i++) {
                $offsets[] = self::u16($locaBytes, $base + $i * 2) * 2;
            }
        } else {
            for ($i = 0; $i <= $numGlyphs; $i++) {
                $offsets[] = self::u32($locaBytes, $base + $i * 4);
            }
        }
        return $offsets;
    }
}
