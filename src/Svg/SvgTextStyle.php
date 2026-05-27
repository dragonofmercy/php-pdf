<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Fully resolved, inheritable text style. Threaded through the parse tree in
 * parallel with SvgPaint so font properties set on an ancestor <g> or <text>
 * cascade into descendant <tspan> runs.
 *
 * @internal
 */
final readonly class SvgTextStyle
{
    public function __construct(
        public string $fontFamily,
        public float $fontSize,
        public bool $bold,
        public bool $italic,
        public TextAnchor $anchor,
    ) {}

    /** CSS/SVG initial values: sans-serif, 16px, normal weight/style, anchor start. */
    public static function initial(): self
    {
        return new self('sans-serif', 16.0, false, false, TextAnchor::START);
    }
}
