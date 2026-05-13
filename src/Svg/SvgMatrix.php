<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/**
 * 2D affine transform in PDF / SVG matrix form:
 *
 *     | a  b  0 |
 *     | c  d  0 |
 *     | e  f  1 |
 *
 * Apply to a point (x, y): (a*x + c*y + e, b*x + d*y + f).
 *
 * Compose semantics: `$t->compose($u)` returns a matrix that applies `$u`
 * first, then `$t`. This matches the SVG transform list convention where
 * `transform="A B C"` applies C first, then B, then A to the inner geometry,
 * and the renderer's viewBox-to-unit composition where the scale lives
 * inside the translate.
 */
final readonly class SvgMatrix
{
    public function __construct(
        public float $a,
        public float $b,
        public float $c,
        public float $d,
        public float $e,
        public float $f,
    ) {}

    public static function identity(): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, 0.0, 0.0);
    }

    public static function translate(float $tx, float $ty = 0.0): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, $tx, $ty);
    }

    public static function scale(float $sx, ?float $sy = null): self
    {
        return new self($sx, 0.0, 0.0, $sy ?? $sx, 0.0, 0.0);
    }

    public static function rotate(float $degrees, float $cx = 0.0, float $cy = 0.0): self
    {
        $rad = deg2rad($degrees);
        $cos = cos($rad);
        $sin = sin($rad);
        $base = new self($cos, $sin, -$sin, $cos, 0.0, 0.0);
        if ($cx === 0.0 && $cy === 0.0) {
            return $base;
        }
        return self::translate($cx, $cy)
            ->compose($base)
            ->compose(self::translate(-$cx, -$cy));
    }

    public static function skewX(float $degrees): self
    {
        return new self(1.0, 0.0, tan(deg2rad($degrees)), 1.0, 0.0, 0.0);
    }

    public static function skewY(float $degrees): self
    {
        return new self(1.0, tan(deg2rad($degrees)), 0.0, 1.0, 0.0, 0.0);
    }

    /**
     * Returns a matrix that, when applied to a point, is equivalent to
     * applying `$other` first and then `$this`. I.e. `t.compose(u).apply(p)
     * == t.apply(u.apply(p))`. Useful for chaining transforms in the order
     * "innermost to outermost" (children-first).
     */
    public function compose(self $other): self
    {
        return new self(
            $other->a * $this->a + $other->b * $this->c,
            $other->a * $this->b + $other->b * $this->d,
            $other->c * $this->a + $other->d * $this->c,
            $other->c * $this->b + $other->d * $this->d,
            $other->e * $this->a + $other->f * $this->c + $this->e,
            $other->e * $this->b + $other->f * $this->d + $this->f,
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    public function apply(float $x, float $y): array
    {
        return [
            $this->a * $x + $this->c * $y + $this->e,
            $this->b * $x + $this->d * $y + $this->f,
        ];
    }

    public function isIdentity(): bool
    {
        return $this->a === 1.0 && $this->b === 0.0 && $this->c === 0.0
            && $this->d === 1.0 && $this->e === 0.0 && $this->f === 0.0;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    public function toArray(): array
    {
        return [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f];
    }
}
