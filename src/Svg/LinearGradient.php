<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/** @internal */
final readonly class LinearGradient implements SvgGradient
{
    /** @param list<GradientStop> $stops */
    public function __construct(
        public float $x1,
        public float $y1,
        public float $x2,
        public float $y2,
        private GradientUnits $units,
        private ?SvgMatrix $transform,
        private array $stops,
        private float $uniformOpacity,
        private SpreadMethod $spreadMethod = SpreadMethod::PAD,
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

    public function spreadMethod(): SpreadMethod
    {
        return $this->spreadMethod;
    }
}
