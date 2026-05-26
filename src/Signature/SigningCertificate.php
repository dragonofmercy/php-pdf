<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Signing credential loaded from a PKCS#12 (.p12/.pfx) bundle: the signer
 * certificate, its private key, and the optional CA chain, all as PEM. The
 * password and key material are never logged or exposed via __toString.
 */
final readonly class SigningCertificate
{
    /**
     * @param list<string> $extraCertificates
     */
    private function __construct(
        public string $certificatePem,
        public string $privateKeyPem,
        public array $extraCertificates,
    ) {}

    public static function fromPkcs12(string $path, string $password): self
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new PdfException("Cannot read PKCS#12 file: {$path}");
        }
        return self::fromPkcs12Bytes($bytes, $password);
    }

    public static function fromPkcs12Bytes(string $bytes, string $password): self
    {
        $parsed = [];
        if (openssl_pkcs12_read($bytes, $parsed, $password) === false) {
            throw new PdfException(
                'Failed to read PKCS#12 bundle (wrong password or malformed): '
                . (openssl_error_string() ?: 'unknown openssl error'),
            );
        }
        if (!is_array($parsed)) {
            throw new PdfException('PKCS#12 bundle parse result is not an array');
        }
        $cert = $parsed['cert'] ?? null;
        $pkey = $parsed['pkey'] ?? null;
        if (!is_string($cert) || !is_string($pkey)) {
            throw new PdfException('PKCS#12 bundle is missing a certificate or private key');
        }
        $extra = [];
        $extracerts = $parsed['extracerts'] ?? null;
        if (is_array($extracerts)) {
            foreach ($extracerts as $c) {
                if (is_string($c)) {
                    $extra[] = $c;
                }
            }
        }
        return new self($cert, $pkey, $extra);
    }
}
