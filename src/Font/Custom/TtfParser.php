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

    /** @param array<string, array{offset: int, length: int}> $dir
     *  @return array{unitsPerEm: int, xMin: int, yMin: int, xMax: int, yMax: int, macStyle: int}
     */
    private static function readHeadTable(string $bytes, array $dir, string $ctx): array
    {
        self::requireTable($dir, 'head', $ctx);
        throw new \RuntimeException('readHeadTable not implemented');
    }

    /** @param array<string, array{offset: int, length: int}> $dir
     *  @return array{ascender: int, descender: int, numberOfHMetrics: int}
     */
    private static function readHheaTable(string $bytes, array $dir, string $ctx): array
    {
        self::requireTable($dir, 'hhea', $ctx);
        throw new \RuntimeException('readHheaTable not implemented');
    }

    /** @param array<string, array{offset: int, length: int}> $dir
     *  @return array{numGlyphs: int}
     */
    private static function readMaxpTable(string $bytes, array $dir, string $ctx): array
    {
        self::requireTable($dir, 'maxp', $ctx);
        throw new \RuntimeException('readMaxpTable not implemented');
    }

    /** @param array<string, array{offset: int, length: int}> $dir
     *  @return array{typoAscender: int, typoDescender: int, capHeight: int, xHeight: int, weightClass: int, fsSelection: int, sFamilyClass: int}
     */
    private static function readOs2Table(string $bytes, array $dir, string $ctx): array
    {
        self::requireTable($dir, 'OS/2', $ctx);
        throw new \RuntimeException('readOs2Table not implemented');
    }

    /** @param array<string, array{offset: int, length: int}> $dir
     *  @return array{italicAngle: int, isFixedPitch: int}
     */
    private static function readPostTable(string $bytes, array $dir, string $ctx): array
    {
        self::requireTable($dir, 'post', $ctx);
        throw new \RuntimeException('readPostTable not implemented');
    }

    /** @param array<string, array{offset: int, length: int}> $dir */
    private static function readNameTable(string $bytes, array $dir, string $ctx): string
    {
        self::requireTable($dir, 'name', $ctx);
        throw new \RuntimeException('readNameTable not implemented');
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
