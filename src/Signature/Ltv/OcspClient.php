<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

/**
 * Sends a DER OCSPRequest to a responder and returns the raw DER OCSPResponse.
 * The network seam, mirroring TsaClient: the default implementation POSTs over
 * HTTP, tests inject a stub returning a canned response.
 */
interface OcspClient
{
    public function request(string $ocspUrl, string $derRequest): string;
}
