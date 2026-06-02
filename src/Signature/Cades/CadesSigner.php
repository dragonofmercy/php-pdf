<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Cades;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Asn1\CertificateFields;
use DragonOfMercy\PhpPdf\Signature\Asn1\Der;
use DragonOfMercy\PhpPdf\Signature\CmsSigner;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;

/**
 * Builds a detached CMS SignedData (DER) with CAdES signed attributes
 * (contentType, messageDigest, signingCertificateV2), signed RSA-SHA256 over the
 * SET OF attribute encoding per RFC 5652 5.4. SignerInfo is v1 with
 * issuerAndSerialNumber; digestAlgorithm sha256; signatureAlgorithm
 * rsaEncryption. RSA keys only.
 *
 * @internal
 */
final readonly class CadesSigner implements CmsSigner
{
    private const string OID_SHA256 = '2.16.840.1.101.3.4.2.1';
    private const string OID_RSA_ENCRYPTION = '1.2.840.113549.1.1.1';
    private const string OID_ID_DATA = '1.2.840.113549.1.7.1';
    private const string OID_SIGNED_DATA = '1.2.840.113549.1.7.2';

    public function sign(string $data, SigningCertificate $certificate): string
    {
        $signerDer = CertificateChain::pemToDer($certificate->certificatePem);
        $fields = CertificateFields::fromDer($signerDer);

        $messageDigest = hash('sha256', $data, true);
        $attributes = new CmsSignedAttributes($messageDigest, $signerDer);

        $signature = $this->signAttributes($attributes->signingForm(), $certificate->privateKeyPem);

        $digestAlgorithm = Der::sequence(Der::oid(self::OID_SHA256), Der::null());
        $signatureAlgorithm = Der::sequence(Der::oid(self::OID_RSA_ENCRYPTION), Der::null());
        $issuerAndSerial = Der::sequence(
            $fields->issuerNameDer(),
            Der::tlv(0x02, $fields->serialNumber()),
        );

        $signerInfo = Der::sequence(
            Der::integer(1),
            $issuerAndSerial,
            $digestAlgorithm,
            $attributes->embeddedForm(),
            $signatureAlgorithm,
            Der::octetString($signature),
        );

        $certificates = $signerDer;
        foreach ($certificate->extraCertificates as $pem) {
            $certificates .= CertificateChain::pemToDer($pem);
        }

        $signedData = Der::sequence(
            Der::integer(1),
            Der::set($digestAlgorithm),
            Der::sequence(Der::oid(self::OID_ID_DATA)),
            Der::contextConstructed(0, $certificates),
            Der::set($signerInfo),
        );

        return Der::sequence(
            Der::oid(self::OID_SIGNED_DATA),
            Der::contextConstructed(0, $signedData),
        );
    }

    private function signAttributes(string $signingForm, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new PdfException('CadesSigner: failed to load private key: '
                . (openssl_error_string() ?: 'unknown openssl error'));
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new PdfException('CadesSigner supports RSA keys only');
        }
        $signatureOut = '';
        if (!openssl_sign($signingForm, $signatureOut, $key, OPENSSL_ALGO_SHA256)) {
            throw new PdfException('CadesSigner: openssl_sign failed: '
                . (openssl_error_string() ?: 'unknown openssl error'));
        }
        if (!is_string($signatureOut)) {
            throw new PdfException('CadesSigner: openssl_sign did not produce a string');
        }
        return $signatureOut;
    }
}
