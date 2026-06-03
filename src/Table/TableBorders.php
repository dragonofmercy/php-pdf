<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Table;

/** Border drawing preset for a table. */
enum TableBorders
{
    case GRID;             // all cell edges
    case HORIZONTAL;       // horizontal rules only (between rows)
    case HEADER_UNDERLINE; // a single rule under the header row
    case NONE;             // no borders
}
