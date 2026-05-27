<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * Fully resolved paint state for a single shape. The parser computes inherited
 * + direct + inline-style values once and stores the result here; the renderer
 * reads this verbatim with no further inheritance work.
 *
 * @see SvgPaint::default() for SVG spec defaults.
 */
final readonly class SvgPaint
{
    /**
     * @param list<float> $strokeDashArray
     */
    public function __construct(
        public ?SvgPaintSource $fill,
        public ?SvgPaintSource $stroke,
        public float $strokeWidth,
        public StrokeLineCap $strokeLineCap,
        public StrokeLineJoin $strokeLineJoin,
        public float $strokeMiterLimit,
        public array $strokeDashArray,
        public float $strokeDashOffset,
        public FillRule $fillRule,
        public float $fillOpacity,
        public float $strokeOpacity,
        public float $opacity,
    ) {}

    public static function default(): self
    {
        return new self(
            fill: SvgColor::black(),
            stroke: null,
            strokeWidth: 1.0,
            strokeLineCap: StrokeLineCap::BUTT,
            strokeLineJoin: StrokeLineJoin::MITER,
            strokeMiterLimit: 4.0,
            strokeDashArray: [],
            strokeDashOffset: 0.0,
            fillRule: FillRule::NONZERO,
            fillOpacity: 1.0,
            strokeOpacity: 1.0,
            opacity: 1.0,
        );
    }

    public function effectiveFillOpacity(): float
    {
        return $this->opacity * $this->fillOpacity;
    }

    public function effectiveStrokeOpacity(): float
    {
        return $this->opacity * $this->strokeOpacity;
    }

    public function withFill(SvgPaintSource $color): self
    {
        return $this->with(fill: $color);
    }

    public function withFillNone(): self
    {
        return $this->with(fillIsNone: true);
    }

    public function withStroke(SvgPaintSource $color): self
    {
        return $this->with(stroke: $color);
    }

    public function withStrokeNone(): self
    {
        return $this->with(strokeIsNone: true);
    }

    public function withStrokeWidth(float $w): self
    {
        return $this->with(strokeWidth: $w);
    }

    public function withStrokeLineCap(StrokeLineCap $cap): self
    {
        return $this->with(strokeLineCap: $cap);
    }

    public function withStrokeLineJoin(StrokeLineJoin $join): self
    {
        return $this->with(strokeLineJoin: $join);
    }

    public function withStrokeMiterLimit(float $m): self
    {
        return $this->with(strokeMiterLimit: $m);
    }

    /**
     * @param list<float> $dashes
     */
    public function withStrokeDashArray(array $dashes): self
    {
        return $this->with(strokeDashArray: $dashes);
    }

    public function withStrokeDashOffset(float $o): self
    {
        return $this->with(strokeDashOffset: $o);
    }

    public function withFillRule(FillRule $r): self
    {
        return $this->with(fillRule: $r);
    }

    public function withFillOpacity(float $a): self
    {
        return $this->with(fillOpacity: $a);
    }

    public function withStrokeOpacity(float $a): self
    {
        return $this->with(strokeOpacity: $a);
    }

    public function withOpacity(float $a): self
    {
        return $this->with(opacity: $a);
    }

    /**
     * @param list<float>|null $strokeDashArray
     */
    private function with(
        ?SvgPaintSource $fill = null,
        bool $fillIsNone = false,
        ?SvgPaintSource $stroke = null,
        bool $strokeIsNone = false,
        ?float $strokeWidth = null,
        ?StrokeLineCap $strokeLineCap = null,
        ?StrokeLineJoin $strokeLineJoin = null,
        ?float $strokeMiterLimit = null,
        ?array $strokeDashArray = null,
        ?float $strokeDashOffset = null,
        ?FillRule $fillRule = null,
        ?float $fillOpacity = null,
        ?float $strokeOpacity = null,
        ?float $opacity = null,
    ): self {
        return new self(
            fill: $fillIsNone ? null : ($fill ?? $this->fill),
            stroke: $strokeIsNone ? null : ($stroke ?? $this->stroke),
            strokeWidth: $strokeWidth ?? $this->strokeWidth,
            strokeLineCap: $strokeLineCap ?? $this->strokeLineCap,
            strokeLineJoin: $strokeLineJoin ?? $this->strokeLineJoin,
            strokeMiterLimit: $strokeMiterLimit ?? $this->strokeMiterLimit,
            strokeDashArray: $strokeDashArray ?? $this->strokeDashArray,
            strokeDashOffset: $strokeDashOffset ?? $this->strokeDashOffset,
            fillRule: $fillRule ?? $this->fillRule,
            fillOpacity: $fillOpacity ?? $this->fillOpacity,
            strokeOpacity: $strokeOpacity ?? $this->strokeOpacity,
            opacity: $opacity ?? $this->opacity,
        );
    }
}
