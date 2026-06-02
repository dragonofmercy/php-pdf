<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Asn1;

/**
 * Builds a DER OCSPRequest (RFC 6960) for one (subject, issuer) certificate
 * pair: a single CertID with a SHA-1 hashAlgorithm, no optional signature and
 * no nonce. The CertID's issuerNameHash and issuerKeyHash are SHA-1 over the
 * issuer's subject Name DER and raw public-key bytes respectively. Matches
 * `openssl ocsp -sha1 -no_nonce` byte for byte.
 *
 * @internal
 */
final readonly class OcspRequestBuilder
{
    private const string SHA1_OID = '1.3.14.3.2.26';

    public static function build(string $subjectDer, string $issuerDer): string
    {
        $subject = CertificateFields::fromDer($subjectDer);
        $issuer = CertificateFields::fromDer($issuerDer);

        $hashAlgorithm = Der::sequence(Der::oid(self::SHA1_OID), Der::null());
        $issuerNameHash = Der::octetString(sha1($issuer->subjectNameDer(), true));
        $issuerKeyHash = Der::octetString(sha1($issuer->subjectPublicKeyBytes(), true));
        $serialNumber = Der::tlv(0x02, $subject->serialNumber());

        $certId = Der::sequence($hashAlgorithm, $issuerNameHash, $issuerKeyHash, $serialNumber);
        $request = Der::sequence($certId);
        $requestList = Der::sequence($request);
        $tbsRequest = Der::sequence($requestList);

        return Der::sequence($tbsRequest);
    }
}
