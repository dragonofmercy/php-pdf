<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

/**
 * Output of CellRenderer::condenseText. Each paragraph is on its own line
 * with a `Tz` horizontal scale percent (100 = no compression). The widths
 * are paragraph widths at 100% scale (in points); the effective on-page
 * width of each line is widths[i] * scales[i] / 100.
 *
 * @internal
 */
final readonly class CondenseResult
{
    /**
     * @param list<string> $lines WinAnsi-encoded line bytes
     * @param list<float> $scales per-line Tz percentage (0..100)
     * @param list<float> $widths per-line widths in points at 100% scale
     */
    public function __construct(
        public array $lines,
        public array $scales,
        public array $widths,
    ) {}
}
