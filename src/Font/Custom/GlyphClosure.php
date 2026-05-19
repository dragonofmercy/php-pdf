<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Computes the transitive closure of a used-GID set: every component glyph
 * referenced (recursively) by a used composite glyph must be embedded too,
 * plus GID 0 (.notdef) which must always be present. Parses only the table
 * directory + head + maxp + loca + glyf from the raw bytes; ParsedTtf is not
 * modified.
 *
 * @internal
 */
final class GlyphClosure
{
    private const int ARG_1_AND_2_ARE_WORDS = 0x0001;
    private const int WE_HAVE_A_SCALE = 0x0008;
    private const int MORE_COMPONENTS = 0x0020;
    private const int WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
    private const int WE_HAVE_A_TWO_BY_TWO = 0x0080;

    /**
     * @param array<int, true> $usedGids
     * @return array<int, true> used + recursive components + GID 0
     */
    public static function expand(string $ttf, array $usedGids, string $context): array
    {
        ['numGlyphs' => $numGlyphs, 'loca' => $loca, 'glyfBase' => $glyfBase] = SfntReader::glyfTables($ttf, $context);

        $result = [];
        $stack = array_keys($usedGids);
        array_unshift($stack, 0); // GID 0 at front => popped last; ordering does not affect the result set

        while ($stack !== []) {
            $gid = array_pop($stack);
            if ($gid < 0 || $gid >= $numGlyphs) {
                throw new PdfException(
                    "Corrupt composite glyph in font '{$context}': component GID {$gid} out of range",
                );
            }
            if (isset($result[$gid])) {
                continue;
            }
            $result[$gid] = true;

            if ($loca[$gid] >= $loca[$gid + 1]) {
                continue;
            }
            $p = $glyfBase + $loca[$gid];
            if (SfntReader::i16($ttf, $p) >= 0) {
                continue;
            }

            $p += 10;
            while (true) {
                $flags = SfntReader::u16($ttf, $p);
                $componentGid = SfntReader::u16($ttf, $p + 2);
                if ($componentGid >= $numGlyphs) {
                    throw new PdfException(
                        "Corrupt composite glyph in font '{$context}': component GID {$componentGid} out of range",
                    );
                }
                if (!isset($result[$componentGid])) {
                    $stack[] = $componentGid;
                }
                $p += 4;
                $p += ($flags & self::ARG_1_AND_2_ARE_WORDS) !== 0 ? 4 : 2;
                if (($flags & self::WE_HAVE_A_SCALE) !== 0) {
                    $p += 2;
                } elseif (($flags & self::WE_HAVE_AN_X_AND_Y_SCALE) !== 0) {
                    $p += 4;
                } elseif (($flags & self::WE_HAVE_A_TWO_BY_TWO) !== 0) {
                    $p += 8;
                }
                if (($flags & self::MORE_COMPONENTS) === 0) {
                    break;
                }
            }
        }

        return $result;
    }
}
