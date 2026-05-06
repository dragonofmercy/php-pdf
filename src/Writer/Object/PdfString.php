<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer\Object;

/**
 * PDF literal string object (PDF 1.7 §7.3.4.2).
 *
 * @internal
 */
final readonly class PdfString implements PdfObject
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
        $escaped = strtr(
            $this->value,
            [
                '\\' => '\\\\',
                '('  => '\\(',
                ')'  => '\\)',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                "\x08" => '\\b',
                "\x0C" => '\\f',
            ]
        );
        return '(' . $escaped . ')';
    }
}
