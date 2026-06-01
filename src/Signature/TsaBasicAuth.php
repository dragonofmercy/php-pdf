<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Optional HTTP Basic credentials for a TSA endpoint. The password is never
 * exposed via __toString or logging.
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
