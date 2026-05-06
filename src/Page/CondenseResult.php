<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

/**
 * Output of CellRenderer::condenseText. Each paragraph is on its own line
 * with a `Tz` horizontal scale percent (100 = no compression).
 *
 * @internal
 */
final readonly class CondenseResult
{
    /**
     * @param list<string> $lines WinAnsi-encoded line bytes
     * @param list<float> $scales per-line Tz percentage (0..100)
     */
    public function __construct(
        public array $lines,
        public array $scales,
    ) {}
}
