<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

/**
 * Output of CellRenderer::wrapText. Carries the wrapped/encoded lines, their
 * per-line widths in points, and metadata used to populate CellResult.
 *
 * @internal
 */
final readonly class WrapResult
{
    /**
     * @param list<string> $lines WinAnsi-encoded line bytes
     * @param list<float>  $widths line widths in points
     */
    public function __construct(
        public array $lines,
        public array $widths,
        public int $brokenWords,
        public bool $textOverflow,
    ) {}
}
