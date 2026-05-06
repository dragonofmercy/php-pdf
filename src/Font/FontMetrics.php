<?php

declare(strict_types=1);

namespace PhpPdf\Font;

/**
 * Value object holding the metric tables of a single PDF Type 1 font, in
 * 1/1000 em units (the standard PDF convention). Widths are keyed by their
 * WinAnsi byte index (0..255) — measurement is performed AFTER `WinAnsiEncoder`
 * has already mapped UTF-8 codepoints to single bytes.
 *
 * @internal
 */
final readonly class FontMetrics
{
    /**
     * @param array<int, int> $widths byte WinAnsi (0..255) => width 1/1000 em
     */
    public function __construct(
        public int $ascent,
        public int $descent,
        public int $capHeight,
        public int $xHeight,
        public int $missingWidth,
        public array $widths,
    ) {}

    /**
     * Width of a single WinAnsi byte at the given size, in points.
     */
    public function charWidth(int $byte, float $size): float
    {
        $em = $this->widths[$byte] ?? $this->missingWidth;
        return $em * $size / 1000.0;
    }

    /**
     * Width of a string (already WinAnsi-encoded) at the given size, in points.
     */
    public function stringWidth(string $encoded, float $size): float
    {
        $total = 0;
        $len = strlen($encoded);
        for ($i = 0; $i < $len; $i++) {
            $total += $this->widths[ord($encoded[$i])] ?? $this->missingWidth;
        }
        return $total * $size / 1000.0;
    }

    public function ascentAt(float $size): float
    {
        return $this->ascent * $size / 1000.0;
    }

    public function descentAt(float $size): float
    {
        return $this->descent * $size / 1000.0;
    }
}
