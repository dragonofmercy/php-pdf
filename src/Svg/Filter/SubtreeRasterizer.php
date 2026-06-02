<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\ImageFormat;
use DragonOfMercy\PhpPdf\Image\PngColorType;
use DragonOfMercy\PhpPdf\Image\PngFilters;
use DragonOfMercy\PhpPdf\Image\PngMetadata;
use DragonOfMercy\PhpPdf\Svg\Align;
use DragonOfMercy\PhpPdf\Svg\MeetOrSlice;
use DragonOfMercy\PhpPdf\Svg\PreserveAspectRatio;
use DragonOfMercy\PhpPdf\Svg\SvgClipped;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgFiltered;
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
 * Solid fills, groups, nested clip/mask/filter wrappers (clip/mask/filter
 * themselves ignored, only the inner child drawn), raster <image> elements,
 * and gradient fills are rendered. Text (<text> / <textPath>) is intentionally
 * SKIPPED: filtering selectable text is out of scope, and the vector renderer
 * still emits it normally on the unfiltered path.
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
        if (!$fill instanceof SvgColor) {
            // none / gradient / pattern: gradient handled in 12c; pattern skipped.
            return;
        }

        $rings = ShapeFlattener::toRings($shape, $shapeCtm);
        if ($rings === []) {
            return;
        }
        $alpha = $paint->effectiveFillOpacity() * $opacity;
        PolygonFiller::fill($buf, $rings, $paint->fillRule, $fill->r, $fill->g, $fill->b, $alpha);
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
        if ($det === 0.0) {
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
