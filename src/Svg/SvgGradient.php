<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/** @internal */
interface SvgGradient extends SvgPaintSource
{
    public function units(): GradientUnits;

    public function transform(): ?SvgMatrix;

    /** @return list<GradientStop> non-empty after resolution */
    public function stops(): array;

    /** Uniform opacity foldable into ca/CA; 1.0 when stop opacities differ. */
    public function uniformOpacity(): float;
}
