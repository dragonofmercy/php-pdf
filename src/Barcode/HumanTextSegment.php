<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * One stretch of human-readable text below or beside a 1D barcode. Position is
 * given in module units (the renderer multiplies by the per-pixel module size).
 *
 * `xModule` is measured from the symbol-area left edge (quiet zone included).
 * `yModule` is measured from the symbol-area top edge.
 * `fontSizeModule` is the desired font size in module units; the renderer
 * converts to pixels using the same module-to-pixel scale.
 *
 * @internal
 */
final readonly class HumanTextSegment
{
    public function __construct(
        public string $text,
        public float $xModule,
        public float $yModule,
        public float $fontSizeModule,
        public TextAnchor $anchor,
    ) {}
}
