<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Deduplicates (fillOpacity, strokeOpacity) pairs into Gs0, Gs1, ... names.
 * Fully opaque (1.0, 1.0) is the no-op: nameFor() returns '' for it and no
 * ExtGState dict is needed in the Form XObject's /Resources.
 */
final class ExtGStateRegistry
{
    /** @var array<string, string> key = "ca|CA" formatted */
    private array $byKey = [];

    /** @var array<string, array{ca: float, CA: float}> name => entry */
    private array $entries = [];

    private int $next = 0;

    public function nameFor(float $fillOpacity, float $strokeOpacity): string
    {
        if ($fillOpacity >= 1.0 && $strokeOpacity >= 1.0) {
            return '';
        }
        $fa = max(0.0, min(1.0, $fillOpacity));
        $sa = max(0.0, min(1.0, $strokeOpacity));
        $key = sprintf('%.6f|%.6f', $fa, $sa);
        if (isset($this->byKey[$key])) {
            return $this->byKey[$key];
        }
        $name = 'Gs' . $this->next++;
        $this->byKey[$key] = $name;
        $this->entries[$name] = ['ca' => $fa, 'CA' => $sa];
        return $name;
    }

    /**
     * @return array<string, array{ca: float, CA: float}>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
