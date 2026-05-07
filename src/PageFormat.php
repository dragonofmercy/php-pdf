<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Standard page formats. Dimensions are returned in millimetres in portrait
 * orientation (width <= height). The Document layer applies orientation and
 * converts to PDF points.
 */
enum PageFormat
{
    case A3;
    case A4;
    case A5;
    case A6;
    case LETTER;
    case LEGAL;

    /**
     * @return array{float, float} [widthMm, heightMm] in portrait
     */
    public function dimensionsMm(): array
    {
        return match ($this) {
            self::A3 => [297.0, 420.0],
            self::A4 => [210.0, 297.0],
            self::A5 => [148.0, 210.0],
            self::A6 => [105.0, 148.0],
            self::LETTER => [215.9, 279.4],
            self::LEGAL => [215.9, 355.6],
        };
    }
}
