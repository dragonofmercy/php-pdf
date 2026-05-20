<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Outline;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Polymorphic link payload attached to a `LinkAnnotation`. Two flavours
 * exposed through named constructors:
 * - {@see url()}         an external URI action (web link, mailto, etc.).
 * - {@see destination()} an internal GoTo action (jumps inside the document).
 *
 * Exactly one of `$url` and `$destination` is non-null. The discrimination is
 * by which constructor was used; consumers (LinkAnnotationEmitter) branch on
 * that.
 */
final readonly class Link
{
    private function __construct(
        public ?string $url = null,
        public ?Destination $destination = null,
    ) {}

    /**
     * External URI link. `$href` is taken as-is and emitted as a PDF literal
     * string (parentheses and backslashes are escaped at serialisation by
     * {@see \DragonOfMercy\PhpPdf\Writer\Object\PdfString}).
     */
    public static function url(string $href): self
    {
        if ($href === '') {
            throw new PdfException('Link URL cannot be empty');
        }
        return new self(url: $href);
    }

    /** Internal GoTo link to a `Destination` (typically built by `Destination::page()`). */
    public static function destination(Destination $dest): self
    {
        return new self(destination: $dest);
    }
}
