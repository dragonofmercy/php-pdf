<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Svg;

/** SVG text-anchor. Controls horizontal alignment of a text chunk about its start point. */
enum TextAnchor: string
{
    case START = 'start';
    case MIDDLE = 'middle';
    case END = 'end';

    public static function fromValue(string $value): self
    {
        return self::tryFrom(trim($value)) ?? self::START;
    }
}
