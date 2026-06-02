<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg\Filter;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * RGBA float pixel buffer for the SVG filter raster pipeline.
 *
 * Channels are stored as straight (non-premultiplied) floats in [0, 1].
 * colorBytes() emits RGB packed bytes; alphaBytes() emits the alpha plane.
 *
 * @internal
 */
final class RasterBuffer
{
    /** @var array<int, float> */
    private array $r;

    /** @var array<int, float> */
    private array $g;

    /** @var array<int, float> */
    private array $b;

    /** @var array<int, float> */
    private array $a;

    public function __construct(public readonly int $width, public readonly int $height)
    {
        if ($width < 1 || $height < 1) {
            throw new PdfException(sprintf('RasterBuffer dimensions must be positive, got %dx%d', $width, $height));
        }

        $size = $width * $height;
        $zeros = array_fill(0, $size, 0.0);

        $this->r = $zeros;
        $this->g = $zeros;
        $this->b = $zeros;
        $this->a = $zeros;
    }

    public static function clamp01(float $v): float
    {
        return $v < 0.0 ? 0.0 : ($v > 1.0 ? 1.0 : $v);
    }

    private function index(int $x, int $y): int
    {
        return $y * $this->width + $x;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function pixel(int $x, int $y): array
    {
        $i = $this->index($x, $y);
        return [$this->r[$i], $this->g[$i], $this->b[$i], $this->a[$i]];
    }

    public function setPixel(int $x, int $y, float $r, float $g, float $b, float $a): void
    {
        $i = $this->index($x, $y);
        $this->r[$i] = $r;
        $this->g[$i] = $g;
        $this->b[$i] = $b;
        $this->a[$i] = $a;
    }

    public function colorBytes(): string
    {
        $out = '';
        $size = $this->width * $this->height;
        for ($i = 0; $i < $size; $i++) {
            $out .= chr($this->quantize($this->r[$i])) . chr($this->quantize($this->g[$i])) . chr($this->quantize($this->b[$i]));
        }
        return $out;
    }

    public function alphaBytes(): string
    {
        $out = '';
        $size = $this->width * $this->height;
        for ($i = 0; $i < $size; $i++) {
            $out .= chr($this->quantize($this->a[$i]));
        }
        return $out;
    }

    /** @return int<0, 255> */
    private function quantize(float $v): int
    {
        $v = self::clamp01($v);
        $byte = (int) floor($v * 255.0 + 0.5);
        return $byte > 255 ? 255 : ($byte < 0 ? 0 : $byte);
    }
}
