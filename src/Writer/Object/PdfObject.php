<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Writer\Object;

/**
 * Marker interface for all PDF value types that can be serialized to the
 * PDF byte stream. Implementations MUST be stateless and deterministic —
 * calling toBytes() multiple times MUST return the same bytes.
 *
 * @internal
 */
interface PdfObject
{
    public function toBytes(): string;
}
