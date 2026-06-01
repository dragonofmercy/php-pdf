<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer\Object;

/**
 * PDF dictionary object (PDF 1.7 §7.3.7). Immutable. Keys are stored by
 * their serialized byte form so overwrites replace in place while preserving
 * insertion order.
 *
 * @internal
 */
final readonly class Dictionary implements PdfObject
{
    /**
     * @param array<string, array{0: Name, 1: PdfObject}> $entries keyed by serialized name bytes
     */
    private function __construct(private array $entries) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return list<array{0: Name, 1: PdfObject}>
     * @internal
     */
    public function entries(): array
    {
        return array_values($this->entries);
    }

    /**
     * Returns the value stored under $key, or null when the key is absent.
     */
    public function get(Name $key): ?PdfObject
    {
        return $this->entries[$key->toBytes()][1] ?? null;
    }

    public function withEntry(Name $key, PdfObject $value): self
    {
        $entries = $this->entries;
        $entries[$key->toBytes()] = [$key, $value];
        return new self($entries);
    }

    public function toBytes(): string
    {
        if ($this->entries === []) {
            return '<< >>';
        }
        $parts = [];
        foreach ($this->entries as [$key, $value]) {
            $parts[] = $key->toBytes() . ' ' . $value->toBytes();
        }
        return '<< ' . implode(' ', $parts) . ' >>';
    }
}
