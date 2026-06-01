<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

/**
 * Fetches an RFC 3161 TimeStampToken for a message imprint. Implementations
 * may talk HTTP (HttpTsaClient) or be stubbed in tests.
 */
interface TsaClient
{
    /**
     * @param string $messageImprint raw digest bytes of the data to timestamp
     * @param string $hashOid dotted OID of the hash algorithm used for the imprint
     * @return string the TimeStampToken DER (a CMS ContentInfo / SignedData)
     */
    public function timestamp(string $messageImprint, string $hashOid): string;
}
