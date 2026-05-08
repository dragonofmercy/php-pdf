<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Binary parser for TrueType fonts. Reads the offset table, table directory,
 * and the metric/encoding tables required for PDF embedding. Does not parse
 * the glyf/loca tables (Phase 3a embeds the entire TTF without subsetting).
 *
 * @internal
 */
final class TtfParser
{
    private const string SFNT_VERSION_TRUETYPE = "\x00\x01\x00\x00";
    private const string SFNT_VERSION_TRUE = 'true';

    public static function parse(string $bytes, string $contextLabel): ParsedTtf
    {
        self::validateMagic($bytes, $contextLabel);

        $tableDir = self::readTableDirectory($bytes, $contextLabel);

        $head = self::readHeadTable($bytes, $tableDir, $contextLabel);
        $hhea = self::readHheaTable($bytes, $tableDir, $contextLabel);
        $maxp = self::readMaxpTable($bytes, $tableDir, $contextLabel);
        $os2 = self::readOs2Table($bytes, $tableDir, $contextLabel);
        $post = self::readPostTable($bytes, $tableDir, $contextLabel);
        $postScriptName = self::readNameTable($bytes, $tableDir, $contextLabel);
        $cmap = self::readCmapTable($bytes, $tableDir, $contextLabel);
        $widths = self::readHmtxTable($bytes, $tableDir, $hhea['numberOfHMetrics'], $maxp['numGlyphs'], $contextLabel);

        $flags = self::computeFlags($head, $os2, $post);

        return new ParsedTtf(
            bytes: $bytes,
            postScriptName: $postScriptName,
            unitsPerEm: $head['unitsPerEm'],
            ascent: $os2['typoAscender'],
            descent: $os2['typoDescender'],
            capHeight: $os2['capHeight'],
            xHeight: $os2['xHeight'],
            bbox: [$head['xMin'], $head['yMin'], $head['xMax'], $head['yMax']],
            italicAngle: $post['italicAngle'],
            weight: $os2['weightClass'],
            flags: $flags,
            cmap: $cmap,
            advanceWidthsByGid: $widths,
        );
    }

    private static function validateMagic(string $bytes, string $ctx): void
    {
        if (strlen($bytes) < 12) {
            throw new PdfException("Invalid TTF file for {$ctx}: file too short");
        }
        $magic = substr($bytes, 0, 4);
        if ($magic === 'OTTO') {
            throw new PdfException("OTF/CFF fonts not supported in this version, use TTF: {$ctx}");
        }
        if ($magic === 'ttcf') {
            throw new PdfException("TrueType collection (.ttc) not supported, provide individual .ttf files: {$ctx}");
        }
        if ($magic !== self::SFNT_VERSION_TRUETYPE && $magic !== self::SFNT_VERSION_TRUE) {
            $hex = strtoupper(bin2hex($magic));
            throw new PdfException("Invalid TTF file for {$ctx}: unknown sfnt version 0x{$hex}");
        }
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    private static function readTableDirectory(string $bytes, string $ctx): array
    {
        $unpacked = unpack('nnumTables', substr($bytes, 4, 2));
        if ($unpacked === false || !is_int($unpacked['numTables'])) {
            throw new PdfException("Invalid TTF file for {$ctx}: cannot read numTables");
        }
        $numTables = $unpacked['numTables'];
        if (strlen($bytes) < 12 + $numTables * 16) {
            throw new PdfException("Invalid TTF file for {$ctx}: table directory truncated");
        }
        $directory = [];
        for ($i = 0; $i < $numTables; $i++) {
            $entryOffset = 12 + $i * 16;
            $tag = substr($bytes, $entryOffset, 4);
            $rec = unpack('Nchecksum/Noffset/Nlength', substr($bytes, $entryOffset + 4, 12));
            if ($rec === false || !is_int($rec['offset']) || !is_int($rec['length'])) {
                throw new PdfException("Invalid TTF file for {$ctx}: corrupt table entry");
            }
            $directory[$tag] = [
                'offset' => $rec['offset'],
                'length' => $rec['length'],
            ];
        }
        return $directory;
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{offset: int, length: int}
     */
    private static function requireTable(array $dir, string $tag, string $ctx): array
    {
        if (!isset($dir[$tag])) {
            throw new PdfException("Missing required TTF table '{$tag}' in {$ctx}");
        }
        return $dir[$tag];
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{unitsPerEm: int, xMin: int, yMin: int, xMax: int, yMax: int, macStyle: int}
     */
    private static function readHeadTable(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'head', $ctx);
        if ($entry['length'] < 54) {
            throw new PdfException("Invalid 'head' table in {$ctx}: too short");
        }
        $offset = $entry['offset'];
        return [
            'unitsPerEm' => self::readUint16($bytes, $offset + 18),
            'xMin' => self::readInt16($bytes, $offset + 36),
            'yMin' => self::readInt16($bytes, $offset + 38),
            'xMax' => self::readInt16($bytes, $offset + 40),
            'yMax' => self::readInt16($bytes, $offset + 42),
            'macStyle' => self::readUint16($bytes, $offset + 44),
        ];
    }

    private static function readUint16(string $bytes, int $offset): int
    {
        $unpacked = unpack('nv', substr($bytes, $offset, 2));
        if ($unpacked === false || !is_int($unpacked['v'])) {
            throw new PdfException("Cannot read uint16 at offset {$offset}");
        }
        return $unpacked['v'];
    }

    private static function readInt16(string $bytes, int $offset): int
    {
        $v = self::readUint16($bytes, $offset);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{ascender: int, descender: int, numberOfHMetrics: int}
     */
    private static function readHheaTable(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'hhea', $ctx);
        if ($entry['length'] < 36) {
            throw new PdfException("Invalid 'hhea' table in {$ctx}: too short");
        }
        return [
            'ascender' => self::readInt16($bytes, $entry['offset'] + 4),
            'descender' => self::readInt16($bytes, $entry['offset'] + 6),
            'numberOfHMetrics' => self::readUint16($bytes, $entry['offset'] + 34),
        ];
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{numGlyphs: int}
     */
    private static function readMaxpTable(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'maxp', $ctx);
        if ($entry['length'] < 6) {
            throw new PdfException("Invalid 'maxp' table in {$ctx}: too short");
        }
        return ['numGlyphs' => self::readUint16($bytes, $entry['offset'] + 4)];
    }

    /**
     * Reads the OS/2 table. Falls back gracefully on older versions where
     * sCapHeight and sxHeight are absent (added in v2): approximates them
     * from the typographic ascender (~70% / ~50%).
     *
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{typoAscender: int, typoDescender: int, capHeight: int, xHeight: int, weightClass: int, fsSelection: int, sFamilyClass: int}
     */
    private static function readOs2Table(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'OS/2', $ctx);
        if ($entry['length'] < 78) {
            throw new PdfException("Invalid 'OS/2' table in {$ctx}: too short");
        }
        $offset = $entry['offset'];
        $version = self::readUint16($bytes, $offset);
        $weightClass = self::readUint16($bytes, $offset + 4);
        $sFamilyClass = self::readInt16($bytes, $offset + 32);
        $fsSelection = self::readUint16($bytes, $offset + 62);
        $typoAscender = self::readInt16($bytes, $offset + 68);
        $typoDescender = self::readInt16($bytes, $offset + 70);

        if ($version >= 2 && $entry['length'] >= 90) {
            $xHeight = self::readInt16($bytes, $offset + 86);
            $capHeight = self::readInt16($bytes, $offset + 88);
        } else {
            $capHeight = (int) round($typoAscender * 0.7);
            $xHeight = (int) round($typoAscender * 0.5);
        }

        return [
            'typoAscender' => $typoAscender,
            'typoDescender' => $typoDescender,
            'capHeight' => $capHeight,
            'xHeight' => $xHeight,
            'weightClass' => $weightClass,
            'fsSelection' => $fsSelection,
            'sFamilyClass' => $sFamilyClass,
        ];
    }

    /**
     * @param array<string, array{offset: int, length: int}> $dir
     * @return array{italicAngle: int, isFixedPitch: int}
     */
    private static function readPostTable(string $bytes, array $dir, string $ctx): array
    {
        $entry = self::requireTable($dir, 'post', $ctx);
        if ($entry['length'] < 32) {
            throw new PdfException("Invalid 'post' table in {$ctx}: too short");
        }
        $italicAngle = self::readUint32($bytes, $entry['offset'] + 4);
        if ($italicAngle >= 0x80000000) {
            $italicAngle -= 0x100000000;
        }
        $isFixedPitch = self::readUint32($bytes, $entry['offset'] + 12);
        return ['italicAngle' => $italicAngle, 'isFixedPitch' => $isFixedPitch];
    }

    private static function readUint32(string $bytes, int $offset): int
    {
        $unpacked = unpack('Nv', substr($bytes, $offset, 4));
        if ($unpacked === false || !is_int($unpacked['v'])) {
            throw new PdfException("Cannot read uint32 at offset {$offset}");
        }
        return $unpacked['v'];
    }

    /**
     * Extracts the PostScriptName (name id 6). Prefers Platform 3 Encoding 1
     * (Microsoft Unicode BMP, decoded as UTF-16BE); falls back to Platform 1
     * Encoding 0 (Macintosh Roman, decoded as ASCII).
     *
     * @param array<string, array{offset: int, length: int}> $dir
     */
    private static function readNameTable(string $bytes, array $dir, string $ctx): string
    {
        $entry = self::requireTable($dir, 'name', $ctx);
        $offset = $entry['offset'];
        if ($entry['length'] < 6) {
            throw new PdfException("Invalid 'name' table in {$ctx}: too short");
        }
        $count = self::readUint16($bytes, $offset + 2);
        $stringOffset = self::readUint16($bytes, $offset + 4);

        /** @var array{0: int, 1: string, 2: int}|null $best */
        $best = null;
        for ($i = 0; $i < $count; $i++) {
            $rec = $offset + 6 + $i * 12;
            $platformId = self::readUint16($bytes, $rec);
            $encodingId = self::readUint16($bytes, $rec + 2);
            $nameId = self::readUint16($bytes, $rec + 6);
            $length = self::readUint16($bytes, $rec + 8);
            $strOffset = self::readUint16($bytes, $rec + 10);

            if ($nameId !== 6) {
                continue;
            }

            $raw = substr($bytes, $offset + $stringOffset + $strOffset, $length);

            $score = match (true) {
                $platformId === 3 && $encodingId === 1 => 2,
                $platformId === 1 && $encodingId === 0 => 1,
                default => 0,
            };
            if ($score === 0) {
                continue;
            }
            if ($best === null || $score > $best[0]) {
                $best = [$score, $raw, $platformId];
            }
        }

        if ($best === null) {
            throw new PdfException("No PostScriptName (name id 6) found in {$ctx}");
        }

        [, $raw, $platformId] = $best;
        $name = $platformId === 3
            ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE')
            : $raw;

        return self::sanitizePostScriptName($name);
    }

    private static function sanitizePostScriptName(string $raw): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._\-]/', '', $raw);
        if (!is_string($sanitized) || $sanitized === '') {
            $sanitized = 'CustomFont';
        }
        if (ctype_digit(substr($sanitized, 0, 1))) {
            $sanitized = 'F' . $sanitized;
        }
        return $sanitized;
    }

    /** @param array<string, array{offset: int, length: int}> $dir
     *  @return array<int, int>
     */
    private static function readCmapTable(string $bytes, array $dir, string $ctx): array
    {
        self::requireTable($dir, 'cmap', $ctx);
        throw new \RuntimeException('readCmapTable not implemented');
    }

    /** @param array<string, array{offset: int, length: int}> $dir
     *  @return array<int, int>
     */
    private static function readHmtxTable(string $bytes, array $dir, int $numberOfHMetrics, int $numGlyphs, string $ctx): array
    {
        self::requireTable($dir, 'hmtx', $ctx);
        throw new \RuntimeException('readHmtxTable not implemented');
    }

    /** @param array{macStyle: int} $head
     *  @param array{fsSelection: int, sFamilyClass: int} $os2
     *  @param array{isFixedPitch: int} $post
     */
    private static function computeFlags(array $head, array $os2, array $post): int
    {
        return 32;
    }
}
