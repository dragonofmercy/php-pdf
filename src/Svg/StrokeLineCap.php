<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

enum StrokeLineCap: string
{
    case BUTT   = 'butt';
    case ROUND  = 'round';
    case SQUARE = 'square';

    /** PDF operator J value (0=butt, 1=round, 2=square). */
    public function toPdfCode(): int
    {
        return match ($this) {
            self::BUTT   => 0,
            self::ROUND  => 1,
            self::SQUARE => 2,
        };
    }
}
