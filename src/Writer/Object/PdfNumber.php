<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer\Object;

/**
 * PDF numeric object (PDF 1.7 §7.3.3).
 *
 * @internal
 */
final readonly class PdfNumber implements PdfObject
{
    private function __construct(
        private int|float $value,
        private bool $isInteger,
    ) {}

    public static function ofInt(int $value): self
    {
        return new self($value, true);
    }

    public static function ofFloat(float $value): self
    {
        return new self($value, false);
    }

    public function toBytes(): string
    {
        if ($this->isInteger) {
            return (string) $this->value;
        }
        $formatted = rtrim(rtrim(number_format((float) $this->value, 6, '.', ''), '0'), '.');
        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
