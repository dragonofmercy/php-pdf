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
     * @param list<string> $lines   line text (engine-encoding native)
     * @param list<float>  $widths  line widths in points
     * @param list<bool>   $justify per-line: true when the line may be justified
     *                              (not the last line of its paragraph)
     */
    public function __construct(
        public array $lines,
        public array $widths,
        public array $justify,
        public int $brokenWords,
        public bool $textOverflow,
    ) {}
}
