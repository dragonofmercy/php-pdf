<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * Generates the textual content of a ToUnicode CMap stream per Adobe
 * Tech Note 5014. One bfchar entry per (codepoint, gid) pair from the
 * font's cmap, omitting GID 0. Non-BMP codepoints are encoded as
 * UTF-16 surrogate pairs in the destination.
 *
 * Phase 3a uses simple bfchar without bfrange compression.
 *
 * @internal
 */
final class ToUnicodeCMap
{
    private const int CHUNK_SIZE = 100;

    public static function generate(ParsedTtf $font): string
    {
        $entries = [];
        foreach ($font->cmap as $codepoint => $gid) {
            if ($gid === 0) {
                continue;
            }
            $entries[$gid] = $codepoint;
        }
        ksort($entries);

        $lines = [
            '/CIDInit /ProcSet findresource begin',
            '12 dict begin',
            'begincmap',
            '/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def',
            '/CMapName /Adobe-Identity-UCS def',
            '/CMapType 2 def',
            '1 begincodespacerange',
            '<0000> <FFFF>',
            'endcodespacerange',
        ];

        $chunks = array_chunk($entries, self::CHUNK_SIZE, preserve_keys: true);
        foreach ($chunks as $chunk) {
            $lines[] = count($chunk) . ' beginbfchar';
            foreach ($chunk as $gid => $codepoint) {
                $lines[] = '<' . self::hex16($gid) . '> <' . self::hexCodepoint($codepoint) . '>';
            }
            $lines[] = 'endbfchar';
        }

        $lines[] = 'endcmap';
        $lines[] = 'CMapName currentdict /CMap defineresource pop';
        $lines[] = 'end';
        $lines[] = 'end';

        return implode("\n", $lines) . "\n";
    }

    private static function hex16(int $v): string
    {
        return strtoupper(str_pad(dechex($v & 0xFFFF), 4, '0', STR_PAD_LEFT));
    }

    private static function hexCodepoint(int $cp): string
    {
        if ($cp <= 0xFFFF) {
            return self::hex16($cp);
        }
        $cp -= 0x10000;
        $high = 0xD800 | (($cp >> 10) & 0x3FF);
        $low = 0xDC00 | ($cp & 0x3FF);
        return self::hex16($high) . self::hex16($low);
    }
}
