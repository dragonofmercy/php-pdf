<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Resolves a page's dimensions in points and validates custom [w, h] formats.
 * Pure: callers keep ownership of their "last used" size state and pass the
 * resolved values in. Shared by Document::addPage and PdfEditor::appendPage.
 */
final class PageSizeResolver
{
    /**
     * @param array{float, float}|null $custom Last custom size in document unit, or null to use $format.
     * @return array{float, float} [widthPoints, heightPoints]
     */
    public static function toPoints(?array $custom, PageFormat $format, Orientation $orientation, Unit $unit): array
    {
        if ($custom !== null) {
            // Custom dimensions are taken verbatim; orientation does not apply.
            [$w, $h] = $custom;
            return [$unit->toPoints($w), $unit->toPoints($h)];
        }

        [$mmW, $mmH] = $format->dimensionsMm();
        if ($orientation === Orientation::LANDSCAPE) {
            [$mmW, $mmH] = [$mmH, $mmW];
        }
        return [Unit::MM->toPoints($mmW), Unit::MM->toPoints($mmH)];
    }

    /**
     * @param array<int|string, mixed> $format
     * @return array{float, float}
     */
    public static function validateCustom(array $format): array
    {
        if (count($format) !== 2 || !array_is_list($format)) {
            throw new PdfException('Custom page format must be [width, height]');
        }
        [$w, $h] = $format;
        if ((!is_int($w) && !is_float($w)) || (!is_int($h) && !is_float($h))) {
            throw new PdfException('Custom page format dimensions must be numeric');
        }
        if ($w <= 0) {
            throw new PdfException('Page width must be positive, got ' . $w);
        }
        if ($h <= 0) {
            throw new PdfException('Page height must be positive, got ' . $h);
        }
        return [(float) $w, (float) $h];
    }
}
