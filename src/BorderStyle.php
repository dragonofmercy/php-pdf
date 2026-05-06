<?php

declare(strict_types=1);

namespace PhpPdf;

/**
 * Style of the border line drawn around a cell.
 *
 * - SOLID: continuous line.
 * - DASHED: emitted as `[3 3] 0 d` dash pattern.
 * - DOTTED: emitted as `[width 2*width] 0 d` (dot size = stroke width, gap = 2x).
 */
enum BorderStyle
{
    case SOLID;
    case DASHED;
    case DOTTED;
}
