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

    /** Common stop opacity foldable into ca/CA; 1.0 when stop opacities are not all equal. */
    public function uniformOpacity(): float;

    public function spreadMethod(): SpreadMethod;
}
