<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer\Object;

/**
 * PDF array object (PDF 1.7 §7.3.6).
 *
 * @internal
 */
final readonly class PdfArray implements PdfObject
{
    /** @var list<PdfObject> */
    private array $elements;

    /**
     * @param list<PdfObject> $elements
     */
    private function __construct(array $elements)
    {
        $this->elements = $elements;
    }

    public static function of(PdfObject ...$elements): self
    {
        return new self(array_values($elements));
    }

    /**
     * @return list<PdfObject>
     * @internal
     */
    public function elements(): array
    {
        return $this->elements;
    }

    public function toBytes(): string
    {
        $parts = array_map(static fn (PdfObject $el): string => $el->toBytes(), $this->elements);
        return '[' . implode(' ', $parts) . ']';
    }
}
