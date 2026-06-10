<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer\Object;

/**
 * PDF name object (PDF 1.7 §7.3.5).
 *
 * @internal
 */
final readonly class Name implements PdfObject
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        return new self($value);
    }

    /** @internal */
    public function value(): string
    {
        return $this->value;
    }

    public function toBytes(): string
    {
        $encoded = '';
        $length = strlen($this->value);
        for ($i = 0; $i < $length; $i++) {
            $byte = $this->value[$i];
            $code = ord($byte);
            if ($code < 0x21 || $code > 0x7E || self::isDelimiterOrHash($byte)) {
                $encoded .= sprintf('#%02X', $code);
            } else {
                $encoded .= $byte;
            }
        }
        return '/' . $encoded;
    }

    private static function isDelimiterOrHash(string $byte): bool
    {
        return str_contains('()<>[]{}/%#', $byte);
    }
}
