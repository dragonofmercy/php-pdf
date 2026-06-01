<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Asn1;

/**
 * Builds an RFC 3161 TimeStampReq DER:
 *
 *   TimeStampReq ::= SEQUENCE {
 *     version           INTEGER { v1(1) },
 *     messageImprint    MessageImprint,
 *     reqPolicy         TSAPolicyId OPTIONAL,    -- omitted
 *     nonce             INTEGER OPTIONAL,
 *     certReq           BOOLEAN DEFAULT FALSE,
 *     extensions        [0] IMPLICIT Extensions OPTIONAL }  -- omitted
 *
 *   MessageImprint ::= SEQUENCE {
 *     hashAlgorithm     AlgorithmIdentifier,
 *     hashedMessage     OCTET STRING }
 *
 * @internal
 */
final class TimeStampReqBuilder
{
    public static function build(string $messageImprint, string $hashOid, string $nonce): string
    {
        $algorithmIdentifier = Der::sequence(
            Der::oid($hashOid),
            Der::null(),
        );
        $imprint = Der::sequence(
            $algorithmIdentifier,
            Der::octetString($messageImprint),
        );

        return Der::sequence(
            Der::integer(1),
            $imprint,
            Der::integerFromBytes($nonce),
            Der::boolean(true),
        );
    }
}
