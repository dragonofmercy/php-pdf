<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Deduplicates ExtGState entries used by an SVG render. An entry carries:
 * - fill alpha (`ca`)
 * - stroke alpha (`CA`)
 * - optional embedded soft-mask index (`smaskEmbeddedIndex`)
 *
 * Fully opaque entries with NO mask are the no-op: nameFor() returns '' and
 * the caller skips emitting any /gs operator. Entries with a mask are NEVER
 * eliminated to no-op (the mask reference is the whole point).
 *
 * ImageEmbedder reads entries() and emits one ExtGState dict per entry, with
 * a /SMask sub-dict when smaskEmbeddedIndex is non-null.
 */
final class ExtGStateRegistry
{
    /** @var array<string, string> key = "ca|CA|smaskIndex" formatted */
    private array $byKey = [];

    /** @var array<string, array{ca: float, CA: float, smaskEmbeddedIndex: ?int}> name => entry */
    private array $entries = [];

    private int $next = 0;

    public function nameFor(float $fillOpacity, float $strokeOpacity): string
    {
        if ($fillOpacity >= 1.0 && $strokeOpacity >= 1.0) {
            return '';
        }
        return $this->register($fillOpacity, $strokeOpacity, null);
    }

    public function nameForWithMask(float $fillOpacity, float $strokeOpacity, int $embeddedMaskIndex): string
    {
        return $this->register($fillOpacity, $strokeOpacity, $embeddedMaskIndex);
    }

    private function register(float $fillOpacity, float $strokeOpacity, ?int $smaskIndex): string
    {
        $fa = max(0.0, min(1.0, $fillOpacity));
        $sa = max(0.0, min(1.0, $strokeOpacity));
        $key = sprintf('%.6f|%.6f|%s', $fa, $sa, $smaskIndex === null ? 'none' : (string) $smaskIndex);
        if (isset($this->byKey[$key])) {
            return $this->byKey[$key];
        }
        $name = 'Gs' . $this->next++;
        $this->byKey[$key] = $name;
        $this->entries[$name] = ['ca' => $fa, 'CA' => $sa, 'smaskEmbeddedIndex' => $smaskIndex];
        return $name;
    }

    /**
     * @return array<string, array{ca: float, CA: float, smaskEmbeddedIndex: ?int}>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
