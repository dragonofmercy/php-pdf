<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

use DragonOfMercy\PhpPdf\Exception\PdfException;

final readonly class ViewBox
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        if ($width <= 0.0) {
            throw new PdfException(
                'ViewBox width must be positive, got ' . self::format($width),
            );
        }
        if ($height <= 0.0) {
            throw new PdfException(
                'ViewBox height must be positive, got ' . self::format($height),
            );
        }
    }

    public static function parse(string $value): self
    {
        $normalised = preg_replace('/[\s,]+/', ' ', trim($value)) ?? '';
        if ($normalised === '') {
            throw new PdfException("Invalid viewBox: '{$value}', expected four numbers");
        }
        $parts = explode(' ', $normalised);
        if (count($parts) !== 4) {
            throw new PdfException("Invalid viewBox: '{$value}', expected four numbers");
        }
        foreach ($parts as $p) {
            if (!is_numeric($p)) {
                throw new PdfException("Invalid viewBox: '{$value}'");
            }
        }
        return new self((float) $parts[0], (float) $parts[1], (float) $parts[2], (float) $parts[3]);
    }

    private static function format(float $v): string
    {
        return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    }
}
