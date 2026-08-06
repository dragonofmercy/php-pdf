<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer\Object;

/**
 * A verbatim PDF token whose bytes are emitted exactly as provided, with no
 * escaping or transformation. ONLY for trusted, pre-serialized PDF fragments
 * (e.g. fixed-width placeholders such as /ByteRange and /Contents in digital
 * signature dictionaries, inline shading pattern dictionaries, or the /W
 * array CidWidthsArray already emits as compressed PDF syntax).
 * NEVER use with user-supplied data.
 *
 * @internal
 */
final readonly class RawValue implements PdfObject
{
    private function __construct(private string $raw) {}

    public static function of(string $raw): self
    {
        return new self($raw);
    }

    public function toBytes(): string
    {
        return $this->raw;
    }
}
