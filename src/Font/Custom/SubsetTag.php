<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * Derives the 6-uppercase-letter subset tag prefixed (with '+') to the
 * PostScriptName of a subsetted font, per PDF 1.7 9.6.4. Deterministic:
 * derived from the PostScriptName plus the sorted closure GID list, so two
 * renders of the same document produce byte-identical fonts (golden tests).
 * The tag is purely an informative label for readers; collisions across
 * fonts have no functional effect.
 * Assumes a 64-bit PHP int (crc32b max 0xFFFFFFFF fits); the tag is an informative reader label only, so cross-platform variance would be harmless anyway.
 *
 * @internal
 */
final class SubsetTag
{
    /**
     * @param list<int> $sortedGids closure GIDs, ascending
     */
    public static function derive(string $postScriptName, array $sortedGids): string
    {
        $seed = $postScriptName . ':' . implode(',', $sortedGids);
        $n = (int) hexdec(hash('crc32b', $seed));
        $tag = '';
        for ($i = 0; $i < 6; $i++) {
            $tag .= chr(65 + $n % 26);
            $n = intdiv($n, 26);
        }
        return $tag;
    }
}
