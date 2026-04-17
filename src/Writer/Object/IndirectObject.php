<?php

declare(strict_types=1);

namespace PhpPdf\Writer\Object;

/**
 * PDF indirect object (PDF 1.7 §7.3.10). Wraps any PdfObject with an
 * object number and generation. Emitted as "N G obj\n<payload>\nendobj\n".
 *
 * @internal
 */
final readonly class IndirectObject implements PdfObject
{
    private function __construct(
        public int $objectNumber,
        public int $generation,
        private PdfObject $payload,
    ) {}

    public static function of(int $objectNumber, int $generation, PdfObject $payload): self
    {
        return new self($objectNumber, $generation, $payload);
    }

    /** @internal */
    public function payload(): PdfObject
    {
        return $this->payload;
    }

    public function reference(): PdfReference
    {
        return PdfReference::to($this->objectNumber, $this->generation);
    }

    public function toBytes(): string
    {
        return $this->objectNumber . ' ' . $this->generation . " obj\n"
            . $this->payload->toBytes() . "\nendobj\n";
    }
}
