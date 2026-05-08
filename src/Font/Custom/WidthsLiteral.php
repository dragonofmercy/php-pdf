<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom;

use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * Emits a pre-formatted /W array string verbatim. Avoids rebuilding what
 * CidWidthsArray::generate() already produces as compressed PDF syntax.
 *
 * @internal
 */
final readonly class WidthsLiteral implements PdfObject
{
    public function __construct(private string $arrayString) {}

    public function toBytes(): string
    {
        return $this->arrayString;
    }
}
