<?php

declare(strict_types=1);

namespace PhpPdf;

/**
 * Vertical alignment of the text block inside a cell when the cell height
 * exceeds the text height.
 */
enum VerticalAlign
{
    case TOP;
    case MIDDLE;
    case BOTTOM;
}
