<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Dedups paint-server pattern entries into P0, P1, ... names for the parent
 * Form XObject's /Resources/Pattern. Two storage modes:
 *
 *  - INLINE: PatternType 2 (shading) dicts inlined directly in /Pattern.
 *    These come from gradients via ShadingBuilder.
 *  - REF: PatternType 1 (tiling) entries that resolve to a child indirect
 *    object. Each carries the index into the renderer's embeddedPatterns
 *    list, which ImageEmbedder will use to allocate the child object number.
 *
 * @internal
 */
final class PatternRegistry
{
    /** @var array<string, string> dict => name */
    private array $byDict = [];

    /** @var array<string, string> name => dict (inline mode only) */
    private array $entries = [];

    /** @var list<array{name: string, embeddedIndex: int}> tiling entries in allocation order */
    private array $refEntries = [];

    private int $next = 0;

    public function nameFor(string $dict): string
    {
        if (isset($this->byDict[$dict])) {
            return $this->byDict[$dict];
        }
        $name = 'P' . $this->next++;
        $this->byDict[$dict] = $name;
        $this->entries[$name] = $dict;
        return $name;
    }

    public function nameForTiling(int $embeddedIndex): string
    {
        $name = 'P' . $this->next++;
        $this->refEntries[] = ['name' => $name, 'embeddedIndex' => $embeddedIndex];
        return $name;
    }

    /** @return array<string, string> name => dict */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return list<array{name: string, embeddedIndex: int}> */
    public function refEntries(): array
    {
        return $this->refEntries;
    }
}
