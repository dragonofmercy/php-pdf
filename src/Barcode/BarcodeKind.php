<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

/** @internal */
enum BarcodeKind: string
{
    case LINEAR_1D = 'linear-1d';
    case MATRIX_2D = 'matrix-2d';
}
