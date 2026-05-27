<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * RGB color in 0..1 unit range (matches PDF's `rg` / `RG` operands).
 * Alpha is NOT carried here; it lives on SvgPaint as fillOpacity / strokeOpacity / opacity.
 */
final readonly class SvgColor implements SvgPaintSource
{
    public function __construct(
        public float $r,
        public float $g,
        public float $b,
    ) {
        if ($r < 0.0 || $r > 1.0) {
            throw new PdfException('SvgColor component must be in [0, 1], got ' . self::fmt($r));
        }
        if ($g < 0.0 || $g > 1.0) {
            throw new PdfException('SvgColor component must be in [0, 1], got ' . self::fmt($g));
        }
        if ($b < 0.0 || $b > 1.0) {
            throw new PdfException('SvgColor component must be in [0, 1], got ' . self::fmt($b));
        }
    }

    public static function fromBytes(int $r, int $g, int $b): self
    {
        $clamp = static fn (int $v): float => max(0.0, min(255.0, (float) $v)) / 255.0;
        return new self($clamp($r), $clamp($g), $clamp($b));
    }

    public static function black(): self
    {
        return new self(0.0, 0.0, 0.0);
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    }
}
