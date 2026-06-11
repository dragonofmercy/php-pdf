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
    // ISO 216 A series
    case A0;
    case A1;
    case A2;
    case A3;
    case A4;
    case A5;
    case A6;
    case A7;

    // ISO 216 B series
    case B4;
    case B5;

    // ISO 269 C series (envelopes)
    case C4;
    case C5;
    case C6;
    case DL;

    // North American
    case LETTER;
    case LEGAL;
    case TABLOID;
    case EXECUTIVE;
    case HALF_LETTER;

    /**
     * @return array{float, float} [widthMm, heightMm] in portrait
     */
    public function dimensionsMm(): array
    {
        return match ($this) {
            self::A0 => [841.0, 1189.0],
            self::A1 => [594.0, 841.0],
            self::A2 => [420.0, 594.0],
            self::A3 => [297.0, 420.0],
            self::A4 => [210.0, 297.0],
            self::A5 => [148.0, 210.0],
            self::A6 => [105.0, 148.0],
            self::A7 => [74.0, 105.0],
            self::B4 => [250.0, 353.0],
            self::B5 => [176.0, 250.0],
            self::C4 => [229.0, 324.0],
            self::C5 => [162.0, 229.0],
            self::C6 => [114.0, 162.0],
            self::DL => [110.0, 220.0],
            self::LETTER => [215.9, 279.4],
            self::LEGAL => [215.9, 355.6],
            self::TABLOID => [279.4, 431.8],
            self::EXECUTIVE => [184.15, 266.7],
            self::HALF_LETTER => [139.7, 215.9],
        };
    }
}
