<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\Color;

/**
 * Neutral, render-agnostic representation of a barcode. Modules are PRE-PADDED
 * with the format's quiet zone (encoder is responsible for the padding so the
 * renderer can treat the array as the full canvas).
 *
 * For LINEAR_1D, `modules` is `list<bool>` (one row of width = total modules
 * including quiet zones on both sides).
 *
 * For MATRIX_2D, `modules` is `list<list<bool>>` (rows of equal width,
 * including quiet zones on all four sides).
 *
 * Both PDF (via Page::barcode -> Barcode::draw) and the SVG renderer consume
 * this VO via Barcode::encode().
 *
 * @internal
 */
final readonly class EncodedBarcode
{
    /**
     * @param list<bool>|list<list<bool>> $modules pre-padded with quiet zones
     * @param list<HumanTextSegment> $humanTextSegments empty for 2D codes without text
     */
    public function __construct(
        public BarcodeKind $kind,
        public array $modules,
        public array $humanTextSegments,
        public Color $color,
        public Orientation $orientation,
        public ?float $bearerBarModules = null,
    ) {}
}
