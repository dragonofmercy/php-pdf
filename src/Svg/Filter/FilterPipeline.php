<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Svg\SvgColor;

/**
 * Executes an ordered list of SVG filter primitives over a source raster.
 *
 * Inputs and results are resolved by name (SourceGraphic, SourceAlpha, ...),
 * with the previous primitive's output flowing implicitly into a null `in`.
 * When color-interpolation-filters is linearRGB, the working pipeline runs in
 * linear light and is converted back to sRGB on the way out.
 *
 * @internal
 */
final class FilterPipeline
{
    public function __construct(
        private ColorInterpolation $colorInterpolation,
        private float $pxPerUserUnit,
        private float $regionX = 0.0,
        private float $regionY = 0.0,
    ) {}

    /**
     * @param list<FilterPrimitive> $primitives
     */
    public function run(RasterBuffer $sourceGraphic, array $primitives): RasterBuffer
    {
        $w = $sourceGraphic->width;
        $h = $sourceGraphic->height;

        if ($primitives === []) {
            return $this->copyBuffer($sourceGraphic);
        }

        $linear = $this->colorInterpolation === ColorInterpolation::LINEAR_RGB;

        $workingSource = $linear ? $this->toLinear($sourceGraphic) : $this->copyBuffer($sourceGraphic);
        $sourceAlpha = $this->alphaOnly($workingSource);

        /** @var array<string, RasterBuffer> $named */
        $named = [
            'SourceGraphic' => $workingSource,
            'SourceAlpha' => $sourceAlpha,
            'BackgroundImage' => $this->transparent($w, $h),
            'BackgroundAlpha' => $this->transparent($w, $h),
            'FillPaint' => $this->transparent($w, $h),
            'StrokePaint' => $this->transparent($w, $h),
        ];

        $last = $workingSource;

        foreach ($primitives as $p) {
            $output = $this->dispatch($p, $named, $last, $linear, $w, $h);

            $subregion = $p->subregion;
            if ($subregion !== null) {
                // primitiveUnits objectBoundingBox not yet applied; subregion coords treated as userSpaceOnUse.
                $output = $this->clipToSubregion($output, $subregion);
            }

            $result = $p->result;
            if ($result !== null) {
                $named[$result] = $output;
            }

            $last = $output;
        }

        return $linear ? $this->toSrgb($last) : $last;
    }

    /**
     * @param array<string, RasterBuffer> $named
     */
    private function dispatch(FilterPrimitive $p, array $named, RasterBuffer $last, bool $linear, int $w, int $h): RasterBuffer
    {
        $px = $this->pxPerUserUnit;

        if ($p instanceof FeGaussianBlur) {
            $input = $this->resolveInput($p->in, $named, $last);
            return BoxBlur::apply($input, $p->stdDeviationX * $px, $p->stdDeviationY * $px);
        }

        if ($p instanceof FeOffset) {
            $input = $this->resolveInput($p->in, $named, $last);
            return Offset::apply($input, (int) round($p->dx * $px), (int) round($p->dy * $px));
        }

        if ($p instanceof FeColorMatrix) {
            $input = $this->resolveInput($p->in, $named, $last);
            return ColorMatrix::apply($input, $p->type, $p->values);
        }

        if ($p instanceof FeComposite) {
            $in = $this->resolveInput($p->in, $named, $last);
            $in2 = $this->resolveInput($p->in2, $named, $last);
            return Composite::apply($in, $in2, $p->operator, $p->k1, $p->k2, $p->k3, $p->k4);
        }

        if ($p instanceof FeBlend) {
            $in = $this->resolveInput($p->in, $named, $last);
            $in2 = $this->resolveInput($p->in2, $named, $last);
            return Blend::apply($in, $in2, $p->mode);
        }

        if ($p instanceof FeFlood) {
            [$r, $g, $b] = $this->floodComponents($p->floodColor, $linear);
            return Flood::apply($w, $h, $r, $g, $b, $p->floodOpacity);
        }

        if ($p instanceof FeMerge) {
            $buffers = [];
            foreach ($p->inputs as $name) {
                $buffers[] = $this->resolveInput($name, $named, $last);
            }
            if ($buffers === []) {
                return $this->transparent($w, $h);
            }
            return Merge::apply($buffers);
        }

        if ($p instanceof FeDropShadow) {
            $base = $this->resolveInput($p->in, $named, $last);
            $offset = Offset::apply($named['SourceAlpha'], (int) round($p->dx * $px), (int) round($p->dy * $px));
            $blurred = BoxBlur::apply($offset, $p->stdDeviationX * $px, $p->stdDeviationY * $px);

            [$r, $g, $b] = $this->floodComponents($p->floodColor, $linear);
            $shadow = new RasterBuffer($w, $h);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $a = $blurred->pixel($x, $y)[3] * $p->floodOpacity;
                    $shadow->setPixel($x, $y, $r, $g, $b, $a);
                }
            }

            return Merge::apply([$shadow, $base]);
        }

        // Unknown primitive: pass the implicit input through unchanged.
        return $last;
    }

    /**
     * @param array<string, RasterBuffer> $named
     */
    private function resolveInput(?string $name, array $named, RasterBuffer $last): RasterBuffer
    {
        if ($name === null) {
            return $last;
        }

        return $named[$name] ?? $named['SourceGraphic'];
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function floodComponents(SvgColor $color, bool $linear): array
    {
        $r = $color->r;
        $g = $color->g;
        $b = $color->b;

        if ($linear) {
            $r = ColorSpace::srgbToLinear($r);
            $g = ColorSpace::srgbToLinear($g);
            $b = ColorSpace::srgbToLinear($b);
        }

        return [$r, $g, $b];
    }

    private function clipToSubregion(RasterBuffer $in, Subregion $sub): RasterBuffer
    {
        $w = $in->width;
        $h = $in->height;

        $minX = $sub->x !== null ? (int) floor(($sub->x - $this->regionX) * $this->pxPerUserUnit) : 0;
        $minY = $sub->y !== null ? (int) floor(($sub->y - $this->regionY) * $this->pxPerUserUnit) : 0;
        $maxX = $sub->width !== null && $sub->x !== null
            ? (int) ceil((($sub->x + $sub->width) - $this->regionX) * $this->pxPerUserUnit)
            : $w;
        $maxY = $sub->height !== null && $sub->y !== null
            ? (int) ceil((($sub->y + $sub->height) - $this->regionY) * $this->pxPerUserUnit)
            : $h;

        $out = new RasterBuffer($w, $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($x >= $minX && $x < $maxX && $y >= $minY && $y < $maxY) {
                    [$r, $g, $b, $a] = $in->pixel($x, $y);
                    $out->setPixel($x, $y, $r, $g, $b, $a);
                }
            }
        }

        return $out;
    }

    /**
     * @param callable(float,float,float,float): array{0:float,1:float,2:float,3:float} $fn
     */
    private function mapPixels(RasterBuffer $in, callable $fn): RasterBuffer
    {
        $out = new RasterBuffer($in->width, $in->height);
        for ($y = 0; $y < $in->height; $y++) {
            for ($x = 0; $x < $in->width; $x++) {
                [$r, $g, $b, $a] = $in->pixel($x, $y);
                [$nr, $ng, $nb, $na] = $fn($r, $g, $b, $a);
                $out->setPixel($x, $y, $nr, $ng, $nb, $na);
            }
        }

        return $out;
    }

    private function copyBuffer(RasterBuffer $in): RasterBuffer
    {
        return $this->mapPixels($in, static fn(float $r, float $g, float $b, float $a): array => [$r, $g, $b, $a]);
    }

    private function toLinear(RasterBuffer $in): RasterBuffer
    {
        return $this->mapPixels($in, static fn(float $r, float $g, float $b, float $a): array => [
            ColorSpace::srgbToLinear($r),
            ColorSpace::srgbToLinear($g),
            ColorSpace::srgbToLinear($b),
            $a,
        ]);
    }

    private function toSrgb(RasterBuffer $in): RasterBuffer
    {
        return $this->mapPixels($in, static fn(float $r, float $g, float $b, float $a): array => [
            ColorSpace::linearToSrgb($r),
            ColorSpace::linearToSrgb($g),
            ColorSpace::linearToSrgb($b),
            $a,
        ]);
    }

    private function alphaOnly(RasterBuffer $in): RasterBuffer
    {
        return $this->mapPixels($in, static fn(float $r, float $g, float $b, float $a): array => [0.0, 0.0, 0.0, $a]);
    }

    private function transparent(int $w, int $h): RasterBuffer
    {
        return new RasterBuffer($w, $h);
    }
}
