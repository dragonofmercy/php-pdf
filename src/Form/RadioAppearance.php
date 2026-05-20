<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Color;

/**
 * Generates the /On and /Off appearance stream contents for a Radio widget.
 * The /On state draws a filled circle (4 cubic Bezier approximation) at 50%
 * of the available radius so the /MK /BC border (if any) remains visible
 * around the dot. The /Off state is intentionally empty.
 *
 * @internal
 */
final class RadioAppearance
{
    /** Bezier circle approximation constant. */
    private const float K = 0.5522847498;

    /**
     * @return array{onContent: string, offContent: string, bbox: array{float, float, float, float}}
     */
    public static function generate(float $widthPt, float $heightPt, Color $textColor): array
    {
        $cx = $widthPt / 2.0;
        $cy = $heightPt / 2.0;
        $r = min($widthPt, $heightPt) / 2.0 * 0.5;
        $k = $r * self::K;

        $colorOp = self::colorOp($textColor);
        $onContent = sprintf(
            "q\n%s rg\n%s %s m\n%s %s %s %s %s %s c\n%s %s %s %s %s %s c\n%s %s %s %s %s %s c\n%s %s %s %s %s %s c\n f\nQ\n",
            $colorOp,
            self::n($cx - $r), self::n($cy),
            self::n($cx - $r), self::n($cy + $k),
            self::n($cx - $k), self::n($cy + $r),
            self::n($cx),       self::n($cy + $r),
            self::n($cx + $k), self::n($cy + $r),
            self::n($cx + $r), self::n($cy + $k),
            self::n($cx + $r), self::n($cy),
            self::n($cx + $r), self::n($cy - $k),
            self::n($cx + $k), self::n($cy - $r),
            self::n($cx),       self::n($cy - $r),
            self::n($cx - $k), self::n($cy - $r),
            self::n($cx - $r), self::n($cy - $k),
            self::n($cx - $r), self::n($cy),
        );

        return [
            'onContent' => $onContent,
            'offContent' => '',
            'bbox' => [0.0, 0.0, $widthPt, $heightPt],
        ];
    }

    private static function colorOp(Color $c): string
    {
        $components = $c->rgbComponents();
        return sprintf('%s %s %s', self::n($components[0]), self::n($components[1]), self::n($components[2]));
    }

    private static function n(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
