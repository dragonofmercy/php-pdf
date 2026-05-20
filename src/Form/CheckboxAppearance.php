<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form;

use DragonOfMercy\PhpPdf\Color;

/**
 * Generates the /On and /Off appearance stream contents for a Checkbox
 * widget. The /On state draws ZapfDingbats glyph 0x34 (check mark) centered
 * in the widget bounding box; the /Off state is intentionally empty.
 *
 * Callers wrap these into PDF Streams and reference them from the widget's
 * /AP /N dictionary.
 *
 * @internal
 */
final class CheckboxAppearance
{
    /**
     * @return array{onContent: string, offContent: string, bbox: array{float, float, float, float}}
     */
    public static function generate(float $widthPt, float $heightPt, Color $textColor): array
    {
        $fontSize = min($widthPt, $heightPt) * 0.8;
        $tx = max(0.0, ($widthPt - $fontSize * 0.788) / 2.0);
        $ty = max(0.0, ($heightPt - $fontSize * 0.7) / 2.0);

        $colorOp = self::colorOp($textColor);
        $onContent = sprintf(
            "q\n%s rg\nBT\n/ZaDb %s Tf\n%s %s Td\n(4) Tj\nET\nQ\n",
            $colorOp,
            self::formatNumber($fontSize),
            self::formatNumber($tx),
            self::formatNumber($ty),
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
        return sprintf(
            '%s %s %s',
            self::formatNumber($components[0]),
            self::formatNumber($components[1]),
            self::formatNumber($components[2]),
        );
    }

    private static function formatNumber(float $v): string
    {
        if ((float) (int) $v === $v) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
