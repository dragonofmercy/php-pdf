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
    public const int HEAD_INDEX_TO_LOC_FORMAT_OFFSET = 50;
    public const int MAXP_NUM_GLYPHS_OFFSET = 4;

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
     * Returns the bytes of the requested table (raw, before any padding).
     * Fail-fast if the table is absent.
     */
    public static function extractTable(string $sfnt, string $tag, string $context): string
    {
        $dir = self::directory($sfnt, $context);
        if (!isset($dir[$tag])) {
            throw new PdfException("Missing '{$tag}' table in sfnt for {$context}");
        }
        return substr($sfnt, $dir[$tag]['offset'], $dir[$tag]['length']);
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
     * Validates the tables required to walk/rebuild glyf, then resolves the
     * directory, loca offsets, numGlyphs and glyf base in one pass. Shared by
     * GlyphClosure (closure walk) and TtfSubsetter (subset rebuild) so the
     * required-table contract and the head/maxp field offsets live in one place.
     *
     * @return array{
     *     dir: array<string, array{offset: int, length: int}>,
     *     indexToLocFormat: int,
     *     numGlyphs: int,
     *     loca: list<int>,
     *     glyfBase: int,
     * }
     */
    public static function glyfTables(string $ttf, string $context): array
    {
        $dir = self::directory($ttf, $context);
        foreach (['glyf', 'loca', 'head', 'maxp'] as $req) {
            if (!isset($dir[$req])) {
                throw new PdfException("Cannot subset font '{$context}': missing required '{$req}' table");
            }
        }

        $indexToLocFormat = self::u16($ttf, $dir['head']['offset'] + self::HEAD_INDEX_TO_LOC_FORMAT_OFFSET);
        $numGlyphs = self::u16($ttf, $dir['maxp']['offset'] + self::MAXP_NUM_GLYPHS_OFFSET);

        return [
            'dir' => $dir,
            'indexToLocFormat' => $indexToLocFormat,
            'numGlyphs' => $numGlyphs,
            'loca' => self::loca($ttf, $dir['loca']['offset'], $indexToLocFormat, $numGlyphs),
            'glyfBase' => $dir['glyf']['offset'],
        ];
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
