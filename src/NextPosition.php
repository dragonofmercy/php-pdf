<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf;

/**
 * Where Page::cell() should leave its internal cursor after rendering, so the
 * next cell() call can omit x/y. Mirrors FPDF's `ln` parameter (0/1/2) but
 * type-safe.
 *
 * - RIGHT:   cursor moves to the right edge of the cell just drawn.
 * - NEWLINE: cursor returns to the x at which the current row started and
 *            advances y by the rendered height (carriage-return + line-feed).
 * - BELOW:   cursor stays at the cell's left edge and advances y (vertical
 *            stack at the same column).
 */
enum NextPosition
{
    case RIGHT;
    case NEWLINE;
    case BELOW;
}
