<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Configuration for a document-level RFC 3161 timestamp (a /DocTimeStamp added
 * as an incremental revision). Carries the Tsa and the /Contents placeholder
 * size in bytes.
 */
final readonly class DocumentTimestamp
{
    public function __construct(
        public Tsa $tsa,
        public int $maxSignatureBytes = 16384,
    ) {
        if ($maxSignatureBytes <= 0) {
            throw new PdfException(sprintf(
                'DocumentTimestamp maxSignatureBytes must be positive, got %d',
                $maxSignatureBytes,
            ));
        }
    }
}
