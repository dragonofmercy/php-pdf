<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Font;

/**
 * One flattened text run with a fully resolved font, size, paint, anchor, and
 * position. A null $x/$y means "continue the current pen position"; a non-null
 * $x starts a new anchored chunk. $dx/$dy are relative offsets applied before
 * the run is drawn. Text is raw UTF-8; WinAnsi encoding happens at render time.
 *
 * @internal
 */
final readonly class SvgTextSpan
{
    public function __construct(
        public string $text,
        public Font $font,
        public float $fontSize,
        public ?SvgColor $fill,
        public float $fillOpacity,
        public ?SvgColor $stroke,
        public float $strokeOpacity,
        public float $strokeWidth,
        public TextAnchor $anchor,
        public ?float $x,
        public ?float $y,
        public float $dx,
        public float $dy,
    ) {}
}
