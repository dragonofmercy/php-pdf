<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\ImageFormat;
use DragonOfMercy\PhpPdf\Image\PngColorType;
use DragonOfMercy\PhpPdf\Image\PngFilters;
use DragonOfMercy\PhpPdf\Image\PngMetadata;
use DragonOfMercy\PhpPdf\Svg\Align;
use DragonOfMercy\PhpPdf\Svg\BoundingBox;
use DragonOfMercy\PhpPdf\Svg\FillRule;
use DragonOfMercy\PhpPdf\Svg\GradientStop;
use DragonOfMercy\PhpPdf\Svg\GradientUnits;
use DragonOfMercy\PhpPdf\Svg\LinearGradient;
use DragonOfMercy\PhpPdf\Svg\MeetOrSlice;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\RadialGradient;
use DragonOfMercy\PhpPdf\Svg\SpreadMethod;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgFiltered;
use DragonOfMercy\PhpPdf\Svg\SvgGradient;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgImage;
use DragonOfMercy\PhpPdf\Svg\SvgMasked;
use DragonOfMercy\PhpPdf\Svg\SvgMatrix;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgShape;
use DragonOfMercy\PhpPdf\Svg\SvgText;
use DragonOfMercy\PhpPdf\Svg\SvgTextPath;

/**
 * Walks an SvgNode subtree and draws it into a RasterBuffer (sRGB straight
 * alpha) for the SVG filter pipeline. This is the SourceGraphic producer:
 * the filter primitives operate on the buffer this class fills.
 *
 * Solid fills, linear/radial gradient fills, basic solid strokes (square
 * joins/caps, v1), groups, nested clip/mask/filter wrappers (clip/mask/filter
 * themselves ignored, only the inner child drawn), and raster <image> elements
 * are rendered. Text (<text> / <textPath>) is intentionally SKIPPED: filtering
 * selectable text is out of scope, and the vector renderer still emits it
 * normally on the unfiltered path. Pattern paint servers are also skipped.
 *
 * @internal
 */
final class SubtreeRasterizer
{
    /** @param list<Image> $embeddedImages indexed by SvgImage::$imageIndex */
    public function __construct(private array $embeddedImages = []) {}

    public function rasterize(SvgNode $node, SvgMatrix $deviceMatrix, int $width, int $height): RasterBuffer
    {
        $buf = new RasterBuffer($width, $height);
        $this->draw($node, $deviceMatrix, $buf, 1.0);
        return $buf;
    }

    private function draw(SvgNode $node, SvgMatrix $ctm, RasterBuffer $buf, float $opacity): void
    {
        if ($node instanceof SvgGroup) {
            $childCtm = $node->transform !== null ? $ctm->compose($node->transform) : $ctm;
            foreach ($node->children as $child) {
                $this->draw($child, $childCtm, $buf, $opacity);
            }
            return;
        }

        if ($node instanceof SvgShape) {
            $this->drawShape($node, $ctm, $buf, $opacity);
            return;
        }

        if ($node instanceof SvgImage) {
            $this->drawImage($node, $ctm, $buf, $opacity);
            return;
        }

        if ($node instanceof SvgClipped) {
            $this->draw($node->child, $ctm, $buf, $opacity);
            return;
        }

        if ($node instanceof SvgMasked) {
            $this->draw($node->child, $ctm, $buf, $opacity);
            return;
        }

        if ($node instanceof SvgFiltered) {
            $this->draw($node->child, $ctm, $buf, $opacity);
            return;
        }

        // SvgText / SvgTextPath / anything else: skipped.
        if ($node instanceof SvgText || $node instanceof SvgTextPath) {
            return;
        }
    }

    private function drawShape(SvgShape $shape, SvgMatrix $ctm, RasterBuffer $buf, float $opacity): void
    {
        $tf = $shape->transform();
        $shapeCtm = $tf !== null ? $ctm->compose($tf) : $ctm;
        $paint = $shape->paint();

        $fill = $paint->fill;
        $fillAlpha = $paint->effectiveFillOpacity() * $opacity;

        if ($fill instanceof SvgColor) {
            $rings = ShapeFlattener::toRings($shape, $shapeCtm);
            if ($rings !== []) {
                PolygonFiller::fill($buf, $rings, $paint->fillRule, $fill->r, $fill->g, $fill->b, $fillAlpha);
            }
        } elseif ($fill instanceof SvgGradient) {
            $this->drawGradientFill($shape, $fill, $shapeCtm, $paint->fillRule, $buf, $fillAlpha);
        }
        // none / pattern fill: skipped (pattern fills inside filters are out of scope).

        $stroke = $paint->stroke;
        if ($stroke instanceof SvgColor && $paint->strokeWidth > 0.0) {
            $strokeAlpha = $paint->effectiveStrokeOpacity() * $opacity;
            $this->drawStroke($shape, $shapeCtm, $paint->strokeWidth, $stroke, $strokeAlpha, $buf);
        }
        // Gradient / pattern strokes inside filters are out of scope (skipped).
    }

    /**
     * Approximates a solid stroke by flattening the shape into device-space
     * rings and filling a width-w quad along every edge. Joins and caps are
     * square (v1): each edge contributes an axis-perpendicular rectangle, so
     * corners overlap rather than mitre. Stroke width is taken in user units and
     * scaled to device by the shapeCtm's average linear scale.
     */
    private function drawStroke(SvgShape $shape, SvgMatrix $shapeCtm, float $userWidth, SvgColor $color, float $alpha, RasterBuffer $buf): void
    {
        if ($alpha <= 0.0) {
            return;
        }
        $rings = ShapeFlattener::toRings($shape, $shapeCtm);
        if ($rings === []) {
            return;
        }
        // Average linear scale of the device matrix (sqrt of the area scale).
        $det = abs($shapeCtm->a * $shapeCtm->d - $shapeCtm->b * $shapeCtm->c);
        $scale = $det > 0.0 ? sqrt($det) : 1.0;
        $half = $userWidth * $scale / 2.0;
        if ($half <= 0.0) {
            return;
        }

        foreach ($rings as $ring) {
            $n = count($ring);
            if ($n < 2) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                $p1 = $ring[$i];
                $p2 = $ring[($i + 1) % $n];
                $ex = $p2['x'] - $p1['x'];
                $ey = $p2['y'] - $p1['y'];
                $len = sqrt($ex * $ex + $ey * $ey);
                if ($len <= 0.0) {
                    continue;
                }
                // Unit normal to the edge.
                $nx = -$ey / $len * $half;
                $ny = $ex / $len * $half;
                $quad = [[
                    ['x' => $p1['x'] + $nx, 'y' => $p1['y'] + $ny],
                    ['x' => $p2['x'] + $nx, 'y' => $p2['y'] + $ny],
                    ['x' => $p2['x'] - $nx, 'y' => $p2['y'] - $ny],
                    ['x' => $p1['x'] - $nx, 'y' => $p1['y'] - $ny],
                ]];
                PolygonFiller::fill($buf, $quad, FillRule::NONZERO, $color->r, $color->g, $color->b, $alpha);
            }
        }
    }

    /**
     * Fills a shape with a gradient by rasterizing its silhouette into a
     * coverage buffer, then sampling the gradient color per covered pixel.
     * The gradient color is evaluated in the gradient's intrinsic coordinate
     * space, reached by inverse-mapping each device pixel through the same
     * matrix the vector renderer builds (shapeCtm . [bbox for oBB] .
     * gradientTransform).
     */
    private function drawGradientFill(SvgShape $shape, SvgGradient $gradient, SvgMatrix $shapeCtm, FillRule $rule, RasterBuffer $buf, float $baseAlpha): void
    {
        $stops = $gradient->stops();
        if ($stops === []) {
            return;
        }

        // Matrix mapping gradient-intrinsic space -> device space.
        $matrix = $shapeCtm;
        if ($gradient->units() === GradientUnits::OBJECT_BOUNDING_BOX) {
            $bbox = BoundingBox::of($shape);
            if ($bbox->isDegenerate()) {
                return;
            }
            $matrix = $matrix
                ->compose(SvgMatrix::translate($bbox->x, $bbox->y))
                ->compose(SvgMatrix::scale($bbox->width, $bbox->height));
        }
        $gt = $gradient->transform();
        if ($gt !== null) {
            $matrix = $matrix->compose($gt);
        }
        $inverse = self::invert($matrix);
        if ($inverse === null) {
            return;
        }

        // Rasterize the silhouette coverage into a private buffer (white, alpha
        // = coverage), then read it back to drive per-pixel gradient sampling.
        $rings = ShapeFlattener::toRings($shape, $shapeCtm);
        if ($rings === []) {
            return;
        }
        $coverage = new RasterBuffer($buf->width, $buf->height);
        PolygonFiller::fill($coverage, $rings, $rule, 1.0, 1.0, 1.0, 1.0);

        $spread = $gradient->spreadMethod();
        for ($py = 0; $py < $buf->height; $py++) {
            for ($px = 0; $px < $buf->width; $px++) {
                $cov = $coverage->pixel($px, $py)[3];
                if ($cov <= 0.0) {
                    continue;
                }
                [$gx, $gy] = $inverse->apply($px + 0.5, $py + 0.5);
                $t = self::gradientParameter($gradient, $gx, $gy);
                if ($t === null) {
                    continue;
                }
                $t = self::applySpread($t, $spread);
                [$r, $g, $b, $stopA] = self::sampleStops($stops, $t);
                $srcA = $stopA * $baseAlpha * $cov;
                if ($srcA <= 0.0) {
                    continue;
                }
                self::composite($buf, $px, $py, $r, $g, $b, $srcA);
            }
        }
    }

    /**
     * Computes the gradient parameter t in [0, 1] (before spread) for a point in
     * the gradient's intrinsic space. Returns null when the point cannot be
     * placed (degenerate radial radius).
     */
    private static function gradientParameter(SvgGradient $gradient, float $gx, float $gy): ?float
    {
        if ($gradient instanceof LinearGradient) {
            $dx = $gradient->x2 - $gradient->x1;
            $dy = $gradient->y2 - $gradient->y1;
            $lenSq = $dx * $dx + $dy * $dy;
            if ($lenSq <= 0.0) {
                return 1.0;
            }
            return (($gx - $gradient->x1) * $dx + ($gy - $gradient->y1) * $dy) / $lenSq;
        }
        if ($gradient instanceof RadialGradient) {
            $r = $gradient->r;
            if ($r <= 0.0) {
                return null;
            }
            // Focal radial gradient: parameterize along the ray from the focal
            // point through the sample point to the circle (centre cx,cy, radius r).
            $fx = $gradient->fx;
            $fy = $gradient->fy;
            $cx = $gradient->cx;
            $cy = $gradient->cy;
            $dx = $gx - $fx;
            $dy = $gy - $fy;
            $fcx = $fx - $cx;
            $fcy = $fy - $cy;
            // Solve |F + s*D - C|^2 = r^2 for the positive scale s; t = 1/s.
            $a = $dx * $dx + $dy * $dy;
            if ($a <= 0.0) {
                return 0.0; // Sample sits on the focal point: innermost stop.
            }
            $b = 2.0 * ($dx * $fcx + $dy * $fcy);
            $c = $fcx * $fcx + $fcy * $fcy - $r * $r;
            $disc = $b * $b - 4.0 * $a * $c;
            if ($disc < 0.0) {
                return 1.0;
            }
            $sq = sqrt($disc);
            $s = (-$b + $sq) / (2.0 * $a);
            if ($s <= 0.0) {
                return 1.0;
            }
            return 1.0 / $s;
        }
        return null;
    }

    private static function applySpread(float $t, SpreadMethod $spread): float
    {
        return match ($spread) {
            SpreadMethod::PAD => max(0.0, min(1.0, $t)),
            SpreadMethod::REPEAT => $t - floor($t),
            SpreadMethod::REFLECT => self::reflect($t),
        };
    }

    private static function reflect(float $t): float
    {
        $m = fmod(abs($t), 2.0);
        return $m > 1.0 ? 2.0 - $m : $m;
    }

    /**
     * Linearly interpolates the (normalized, monotone) gradient stops at t.
     *
     * @param list<GradientStop> $stops
     * @return array{0: float, 1: float, 2: float, 3: float} r, g, b, alpha
     */
    private static function sampleStops(array $stops, float $t): array
    {
        $first = $stops[0];
        if ($t <= $first->offset) {
            return [$first->color->r, $first->color->g, $first->color->b, $first->opacity];
        }
        $last = $stops[count($stops) - 1];
        if ($t >= $last->offset) {
            return [$last->color->r, $last->color->g, $last->color->b, $last->opacity];
        }
        for ($i = 1, $n = count($stops); $i < $n; $i++) {
            $s0 = $stops[$i - 1];
            $s1 = $stops[$i];
            if ($t <= $s1->offset) {
                $span = $s1->offset - $s0->offset;
                $f = $span > 0.0 ? ($t - $s0->offset) / $span : 0.0;
                return [
                    $s0->color->r + ($s1->color->r - $s0->color->r) * $f,
                    $s0->color->g + ($s1->color->g - $s0->color->g) * $f,
                    $s0->color->b + ($s1->color->b - $s0->color->b) * $f,
                    $s0->opacity + ($s1->opacity - $s0->opacity) * $f,
                ];
            }
        }
        return [$last->color->r, $last->color->g, $last->color->b, $last->opacity];
    }

    private function drawImage(SvgImage $img, SvgMatrix $ctm, RasterBuffer $buf, float $opacity): void
    {
        if (!isset($this->embeddedImages[$img->imageIndex])) {
            return;
        }
        $image = $this->embeddedImages[$img->imageIndex];
        $decoded = $this->decodeToRgba($image);
        if ($decoded === null) {
            return; // Unsupported format (e.g. JPEG without gd) or SVG-in-SVG: skip.
        }
        [$srcW, $srcH, $pixels] = $decoded;
        if ($srcW < 1 || $srcH < 1) {
            return;
        }

        // Placement rect in the image's local user space (mirror renderImage).
        [$fx, $fy, $fw, $fh] = self::fittedRect(
            $img->x, $img->y, $img->width, $img->height,
            $img->intrinsicWidth, $img->intrinsicHeight, $img->aspectRatio,
        );
        if ($fw <= 0.0 || $fh <= 0.0) {
            return;
        }

        // Full device transform = ctm . imageTransform.
        $deviceMatrix = $img->transform !== null ? $ctm->compose($img->transform) : $ctm;
        $inverse = self::invert($deviceMatrix);
        if ($inverse === null) {
            return; // Degenerate transform: nothing to draw.
        }

        // Device-space bounding box of the four placement-rect corners.
        $corners = [
            $deviceMatrix->apply($fx, $fy),
            $deviceMatrix->apply($fx + $fw, $fy),
            $deviceMatrix->apply($fx + $fw, $fy + $fh),
            $deviceMatrix->apply($fx, $fy + $fh),
        ];
        $minX = $maxX = $corners[0][0];
        $minY = $maxY = $corners[0][1];
        foreach ($corners as [$cxp, $cyp]) {
            $minX = min($minX, $cxp);
            $maxX = max($maxX, $cxp);
            $minY = min($minY, $cyp);
            $maxY = max($maxY, $cyp);
        }
        $pxMin = max(0, (int) floor($minX));
        $pyMin = max(0, (int) floor($minY));
        $pxMax = min($buf->width - 1, (int) floor($maxX));
        $pyMax = min($buf->height - 1, (int) floor($maxY));
        if ($pxMin > $pxMax || $pyMin > $pyMax) {
            return;
        }

        $imgOpacity = $img->opacity * $opacity;
        for ($py = $pyMin; $py <= $pyMax; $py++) {
            for ($px = $pxMin; $px <= $pxMax; $px++) {
                // Inverse-map the pixel centre to user space.
                [$ux, $uy] = $inverse->apply($px + 0.5, $py + 0.5);
                if ($ux < $fx || $ux >= $fx + $fw || $uy < $fy || $uy >= $fy + $fh) {
                    continue;
                }
                // User -> source pixel (nearest neighbour, deterministic).
                $sx = (int) floor(($ux - $fx) / $fw * $srcW);
                $sy = (int) floor(($uy - $fy) / $fh * $srcH);
                if ($sx < 0) {
                    $sx = 0;
                } elseif ($sx >= $srcW) {
                    $sx = $srcW - 1;
                }
                if ($sy < 0) {
                    $sy = 0;
                } elseif ($sy >= $srcH) {
                    $sy = $srcH - 1;
                }
                $i = ($sy * $srcW + $sx) * 4;
                $srcA = $pixels[$i + 3] * $imgOpacity;
                if ($srcA <= 0.0) {
                    continue;
                }
                self::composite($buf, $px, $py, $pixels[$i], $pixels[$i + 1], $pixels[$i + 2], $srcA);
            }
        }
    }

    /**
     * Source-over composite of one straight-RGBA source sample onto the buffer.
     */
    private static function composite(RasterBuffer $buf, int $px, int $py, float $r, float $g, float $b, float $srcA): void
    {
        [$dstR, $dstG, $dstB, $dstA] = $buf->pixel($px, $py);
        $outA = $srcA + $dstA * (1.0 - $srcA);
        if ($outA <= 0.0) {
            $buf->setPixel($px, $py, 0.0, 0.0, 0.0, 0.0);
            return;
        }
        $inv = 1.0 / $outA;
        $buf->setPixel(
            $px,
            $py,
            ($r * $srcA + $dstR * $dstA * (1.0 - $srcA)) * $inv,
            ($g * $srcA + $dstG * $dstA * (1.0 - $srcA)) * $inv,
            ($b * $srcA + $dstB * $dstA * (1.0 - $srcA)) * $inv,
            $outA,
        );
    }

    /**
     * Decodes an embedded raster image into a flat straight-RGBA float array
     * (values in [0, 1], row-major top-down). Returns null for formats that
     * cannot be decoded here (JPEG without ext-gd, or nested SVG).
     *
     * @return array{0: int, 1: int, 2: list<float>}|null [width, height, rgba]
     */
    private function decodeToRgba(Image $image): ?array
    {
        if ($image->format === ImageFormat::PNG && $image->metadata instanceof PngMetadata) {
            return self::decodePng($image->metadata);
        }
        if ($image->format === ImageFormat::JPEG && extension_loaded('gd')) {
            return self::decodeWithGd($image->bytes);
        }
        return null;
    }

    /**
     * @return array{0: int, 1: int, 2: list<float>}|null
     */
    private static function decodePng(PngMetadata $png): ?array
    {
        if ($png->bitDepth !== 8) {
            return null; // Only 8-bit channels supported here (bit-depth 16 already rejected upstream).
        }
        $inflated = @gzuncompress($png->idatBytes);
        if ($inflated === false) {
            return null;
        }
        $w = $png->width;
        $h = $png->height;

        $channels = match ($png->colorType) {
            PngColorType::GRAY       => 1,
            PngColorType::RGB        => 3,
            PngColorType::PALETTE    => 1,
            PngColorType::GRAY_ALPHA => 2,
            PngColorType::RGB_ALPHA  => 4,
        };
        $raw = PngFilters::unfilter($inflated, $w, $h, $channels);

        $palette = $png->palette;
        /** @var list<float> $out */
        $out = [];
        $offset = 0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                [$r, $g, $b, $a] = match ($png->colorType) {
                    PngColorType::GRAY => self::gray($raw, $offset),
                    PngColorType::RGB => self::rgb($raw, $offset),
                    PngColorType::PALETTE => self::palette($raw, $offset, $palette),
                    PngColorType::GRAY_ALPHA => self::grayAlpha($raw, $offset),
                    PngColorType::RGB_ALPHA => self::rgbAlpha($raw, $offset),
                };
                $out[] = $r;
                $out[] = $g;
                $out[] = $b;
                $out[] = $a;
                $offset += $channels;
            }
        }
        return [$w, $h, $out];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function gray(string $raw, int $o): array
    {
        $v = ord($raw[$o]) / 255.0;
        return [$v, $v, $v, 1.0];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function rgb(string $raw, int $o): array
    {
        return [ord($raw[$o]) / 255.0, ord($raw[$o + 1]) / 255.0, ord($raw[$o + 2]) / 255.0, 1.0];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function grayAlpha(string $raw, int $o): array
    {
        $v = ord($raw[$o]) / 255.0;
        return [$v, $v, $v, ord($raw[$o + 1]) / 255.0];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function rgbAlpha(string $raw, int $o): array
    {
        return [ord($raw[$o]) / 255.0, ord($raw[$o + 1]) / 255.0, ord($raw[$o + 2]) / 255.0, ord($raw[$o + 3]) / 255.0];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    private static function palette(string $raw, int $o, ?string $palette): array
    {
        if ($palette === null) {
            return [0.0, 0.0, 0.0, 1.0];
        }
        $idx = ord($raw[$o]) * 3;
        if ($idx + 2 >= strlen($palette)) {
            return [0.0, 0.0, 0.0, 1.0];
        }
        return [ord($palette[$idx]) / 255.0, ord($palette[$idx + 1]) / 255.0, ord($palette[$idx + 2]) / 255.0, 1.0];
    }

    /**
     * @return array{0: int, 1: int, 2: list<float>}|null
     */
    private static function decodeWithGd(string $bytes): ?array
    {
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        /** @var list<float> $out */
        $out = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($im, $x, $y);
                $out[] = (($rgb >> 16) & 0xFF) / 255.0;
                $out[] = (($rgb >> 8) & 0xFF) / 255.0;
                $out[] = ($rgb & 0xFF) / 255.0;
                // gd alpha is 0 (opaque) .. 127 (transparent); JPEG has no alpha.
                $out[] = 1.0;
            }
        }
        imagedestroy($im);
        return [$w, $h, $out];
    }

    /**
     * Fits an intrinsic raster size into a viewport rect per preserveAspectRatio.
     * Mirrors Renderer::fittedRect so the filter raster matches the vector path.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} fx, fy, fw, fh
     */
    private static function fittedRect(
        float $vx, float $vy, float $vw, float $vh,
        int $iw, int $ih, PreserveAspectRatio $ar,
    ): array {
        if ($ar->align === Align::NONE || $iw <= 0 || $ih <= 0) {
            return [$vx, $vy, $vw, $vh];
        }
        $sx = $vw / $iw;
        $sy = $vh / $ih;
        $s = $ar->meetOrSlice === MeetOrSlice::MEET ? min($sx, $sy) : max($sx, $sy);
        $fw = $iw * $s;
        $fh = $ih * $s;
        $dx = match ($ar->align) {
            Align::X_MIN_Y_MIN, Align::X_MIN_Y_MID, Align::X_MIN_Y_MAX => 0.0,
            Align::X_MID_Y_MIN, Align::X_MID_Y_MID, Align::X_MID_Y_MAX => ($vw - $fw) / 2.0,
            default => $vw - $fw,
        };
        $dy = match ($ar->align) {
            Align::X_MIN_Y_MIN, Align::X_MID_Y_MIN, Align::X_MAX_Y_MIN => 0.0,
            Align::X_MIN_Y_MID, Align::X_MID_Y_MID, Align::X_MAX_Y_MID => ($vh - $fh) / 2.0,
            default => $vh - $fh,
        };
        return [$vx + $dx, $vy + $dy, $fw, $fh];
    }

    /**
     * Inverts an affine SvgMatrix. Returns null when the matrix is singular.
     */
    private static function invert(SvgMatrix $m): ?SvgMatrix
    {
        $det = $m->a * $m->d - $m->b * $m->c;
        if (abs($det) < 1e-12) {
            return null;
        }
        $invDet = 1.0 / $det;
        $a = $m->d * $invDet;
        $b = -$m->b * $invDet;
        $c = -$m->c * $invDet;
        $d = $m->a * $invDet;
        $e = -($m->e * $a + $m->f * $c);
        $f = -($m->e * $b + $m->f * $d);
        return new SvgMatrix($a, $b, $c, $d, $e, $f);
    }
}
