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
        if (strlen($bytes) < 12) {
            throw new PdfException("Invalid TTF file for {$contextLabel}: file too short");
        }
        $magic = substr($bytes, 0, 4);
        if ($magic === 'OTTO') {
            throw new PdfException(
                "OTF/CFF fonts not supported in this version, use TTF: {$contextLabel}",
            );
        }
        if ($magic === 'ttcf') {
            throw new PdfException(
                "TrueType collection (.ttc) not supported, provide individual .ttf files: {$contextLabel}",
            );
        }
        if ($magic !== self::SFNT_VERSION_TRUETYPE && $magic !== self::SFNT_VERSION_TRUE) {
            $hex = strtoupper(bin2hex($magic));
            throw new PdfException(
                "Invalid TTF file for {$contextLabel}: unknown sfnt version 0x{$hex}",
            );
        }

        throw new PdfException("TtfParser not yet implemented past magic bytes for {$contextLabel}");
    }
}
