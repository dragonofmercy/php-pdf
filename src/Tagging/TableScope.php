<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tagging;

/**
 * Table-header scope, emitted as /A <</O /Table /Scope /Column|Row>> on a TH
 * structure element (PDF/UA clause 7.5).
 */
enum TableScope: string
{
    case Column = 'Column';
    case Row = 'Row';
}
