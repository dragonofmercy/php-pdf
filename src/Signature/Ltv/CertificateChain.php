<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;

/**
 * Helpers to assemble the PEM certificate chain from a signing credential, to
 * convert PEM to DER, and to read CRL distribution point URLs from a
 * certificate. Pure, network-free; the actual fetch lives in
 * HttpCrlValidationDataSource.
 *
 * @internal
 */
final class CertificateChain
{
    /**
     * Signer certificate first, then the credential's extra (issuer) certs.
     *
     * @return list<string> PEM strings
     */
    public static function chainPem(SigningCertificate $credential): array
    {
        return [$credential->certificatePem, ...$credential->extraCertificates];
    }

    /**
     * Strips PEM armor and base64-decodes to DER.
     */
    public static function pemToDer(string $pem, string $label = 'CERTIFICATE'): string
    {
        if (preg_match("~-----BEGIN {$label}-----(.+?)-----END {$label}-----~s", $pem, $m) !== 1) {
            throw new PdfException("{$label} PEM armor not found");
        }
        $der = base64_decode(preg_replace('~\s+~', '', $m[1]) ?? '', true);
        if ($der === false || $der === '') {
            throw new PdfException("{$label} base64 body did not decode");
        }
        return $der;
    }

    /**
     * Reads the CRL distribution point URLs declared by a certificate. Returns
     * an empty list when the certificate has no CDP extension.
     *
     * @return list<string>
     */
    public static function crlUrls(string $certPem): array
    {
        $cdp = self::extensionText($certPem, 'crlDistributionPoints', 'CRL distribution points');
        return $cdp === null ? [] : self::crlUrlsFromExtensionText($cdp);
    }

    /**
     * Extracts URI tokens from the human-readable crlDistributionPoints text
     * that openssl_x509_parse produces.
     *
     * @return list<string>
     */
    public static function crlUrlsFromExtensionText(string $text): array
    {
        return self::uriTokens($text, '~URI:([^\s,)]+)~');
    }

    /**
     * Reads the OCSP responder URLs from a certificate's Authority Information
     * Access extension. Returns an empty list when the certificate has no AIA
     * OCSP entry.
     *
     * @return list<string>
     */
    public static function ocspUrls(string $certPem): array
    {
        $aia = self::extensionText($certPem, 'authorityInfoAccess', 'OCSP responder URLs');
        return $aia === null ? [] : self::ocspUrlsFromExtensionText($aia);
    }

    /**
     * Extracts OCSP responder URIs from the human-readable authorityInfoAccess
     * text that openssl_x509_parse produces (lines like "OCSP - URI:http://...").
     *
     * @return list<string>
     */
    public static function ocspUrlsFromExtensionText(string $text): array
    {
        return self::uriTokens($text, '~OCSP - URI:([^\s,)]+)~');
    }

    /**
     * Returns the human-readable text of a named certificate extension, or null
     * when the certificate has no such extension. Throws when the certificate
     * itself cannot be parsed.
     */
    private static function extensionText(string $certPem, string $extensionKey, string $purpose): ?string
    {
        $parsed = openssl_x509_parse($certPem);
        if (!is_array($parsed)) {
            throw new PdfException("Could not parse certificate for {$purpose}");
        }
        $extensions = $parsed['extensions'] ?? null;
        if (!is_array($extensions)) {
            return null;
        }
        $value = $extensions[$extensionKey] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * Captures the first group of every match of $regex in $text, de-duplicated
     * and re-indexed.
     *
     * @return list<string>
     */
    private static function uriTokens(string $text, string $regex): array
    {
        if (preg_match_all($regex, $text, $m) === false) {
            return [];
        }
        return array_values(array_unique($m[1]));
    }

    /**
     * True when a certificate is self-signed (issuer DN == subject DN), i.e. a
     * root that needs no revocation entry.
     */
    public static function isSelfSigned(string $certPem): bool
    {
        $parsed = openssl_x509_parse($certPem);
        if (!is_array($parsed)) {
            throw new PdfException('Could not parse certificate to determine self-signed status');
        }
        return isset($parsed['subject'], $parsed['issuer']) && $parsed['subject'] === $parsed['issuer'];
    }
}
