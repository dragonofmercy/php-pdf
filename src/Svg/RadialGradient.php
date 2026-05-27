<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/** @internal */
final readonly class RadialGradient implements SvgGradient
{
    /** @param list<GradientStop> $stops */
    public function __construct(
        public float $cx,
        public float $cy,
        public float $r,
        public float $fx,
        public float $fy,
        private GradientUnits $units,
        private ?SvgMatrix $transform,
        private array $stops,
        private float $uniformOpacity,
    ) {}

    public function units(): GradientUnits
    {
        return $this->units;
    }

    public function transform(): ?SvgMatrix
    {
        return $this->transform;
    }

    public function stops(): array
    {
        return $this->stops;
    }

    public function uniformOpacity(): float
    {
        return $this->uniformOpacity;
    }
}
