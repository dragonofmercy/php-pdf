<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Configuration for signing a document: the credential, the target
 * SignatureField name, optional signer metadata, the signing time, and the
 * /Contents placeholder size in bytes.
 */
final readonly class Signature
{
    public function __construct(
        public SigningCertificate $certificate,
        public string $fieldName,
        public ?string $reason,
        public ?string $location,
        public ?string $contactInfo,
        public DateTimeImmutable $signedAt,
        public int $maxSignatureBytes,
    ) {
        if ($fieldName === '') {
            throw new PdfException('Signature field name cannot be empty');
        }
        if ($maxSignatureBytes <= 0) {
            throw new PdfException(sprintf(
                'Signature maxSignatureBytes must be positive, got %d',
                $maxSignatureBytes,
            ));
        }
    }
}
