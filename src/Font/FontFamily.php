<?php

declare(strict_types=1);

namespace PhpPdf\Font;

/**
 * One of the three standard PDF font families. Used internally by the `Font`
 * public builder.
 *
 * @internal
 */
enum FontFamily
{
    case HELVETICA;
    case TIMES;
    case COURIER;
}
