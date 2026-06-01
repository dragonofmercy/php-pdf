<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Optional HTTP Basic credentials for a TSA endpoint. No __toString is defined,
 * so the credential is not accidentally stringified into logs; the password
 * stays readable as a property for the transport that needs it.
 */
final readonly class TsaBasicAuth
{
    public function __construct(
        public string $username,
        public string $password,
    ) {
        if ($username === '') {
            throw new PdfException('TSA basic-auth username cannot be empty');
        }
    }

    public function headerValue(): string
    {
        return 'Basic ' . base64_encode($this->username . ':' . $this->password);
    }
}
