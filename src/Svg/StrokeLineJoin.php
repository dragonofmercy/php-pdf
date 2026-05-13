<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

enum StrokeLineJoin: string
{
    case MITER = 'miter';
    case ROUND = 'round';
    case BEVEL = 'bevel';

    /** PDF operator j value (0=miter, 1=round, 2=bevel). */
    public function toPdfCode(): int
    {
        return match ($this) {
            self::MITER => 0,
            self::ROUND => 1,
            self::BEVEL => 2,
        };
    }
}
