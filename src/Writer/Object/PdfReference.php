<?php

declare(strict_types=1);

namespace PhpPdf\Writer\Object;

/**
 * PDF indirect reference (PDF 1.7 §7.3.10). Serialized as "N G R".
 *
 * @internal
 */
final readonly class PdfReference implements PdfObject
{
    private function __construct(
        public int $objectNumber,
        public int $generation,
    ) {}

    public static function to(int $objectNumber, int $generation): self
    {
        return new self($objectNumber, $generation);
    }

    public function toBytes(): string
    {
        return $this->objectNumber . ' ' . $this->generation . ' R';
    }
}
