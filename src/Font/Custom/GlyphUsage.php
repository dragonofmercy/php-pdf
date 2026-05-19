<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

/**
 * Mutable collector of glyph IDs actually emitted into content streams,
 * bucketed by the FontRegistry usage key (CustomFontKey::toRegistryKey()).
 * This is the only mutable piece of the custom-font pipeline; it is injected
 * explicitly (never a singleton) so two Documents never share usage.
 *
 * @internal
 */
final class GlyphUsage
{
    /** @var array<string, array<int, true>> usageKey => set of used GIDs */
    private array $used = [];

    public function record(string $usageKey, int $gid): void
    {
        $this->used[$usageKey][$gid] = true;
    }

    /**
     * @return array<int, true> set of used GIDs ([] if the key was never seen)
     */
    public function usedGids(string $usageKey): array
    {
        return $this->used[$usageKey] ?? [];
    }
}
