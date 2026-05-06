<?php

declare(strict_types=1);

namespace PhpPdf\Page;

/**
 * Output of CellRenderer::shrinkText. The effective size and leading apply
 * uniformly to every paragraph (ratio computed against the longest line).
 *
 * @internal
 */
final readonly class ShrinkResult
{
    /**
     * @param list<string> $lines WinAnsi-encoded line bytes
     * @param list<float> $widths line widths in points (at effectiveSize)
     */
    public function __construct(
        public array $lines,
        public array $widths,
        public float $effectiveSize,
        public float $effectiveLeading,
        public bool $textOverflow,
    ) {}
}
