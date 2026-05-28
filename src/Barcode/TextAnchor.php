<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/**
 * Logical alignment for a HumanTextSegment. Values map 1:1 onto the SVG
 * `text-anchor` attribute literals.
 *
 * @internal
 */
enum TextAnchor: string
{
    case START = 'start';
    case MIDDLE = 'middle';
    case END = 'end';
}
