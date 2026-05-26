<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Support;

/**
 * Generates an ephemeral self-signed certificate, private key, and PKCS#12
 * bundle for signing tests. Nothing is persisted beyond the returned strings.
 */
final class TestCertificate
{
    /**
     * @return array{certPem: string, keyPem: string, p12: string, password: string}
     */
    public static function generate(string $password = 'test-pass'): array
    {
        $config = self::resolveOpensslConfig();

        $keyOptions = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        if ($config !== null) {
            $keyOptions['config'] = $config;
        }
        $rawKey = openssl_pkey_new($keyOptions);
        if (!($rawKey instanceof \OpenSSLAsymmetricKey)) {
            throw new \RuntimeException('openssl_pkey_new failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        // Keep a separately typed variable so that passing $csrKey by-ref to openssl_csr_new
        // does not cause PHPStan to lose its OpenSSLAsymmetricKey narrowing on $signingKey.
        $csrKey = $rawKey;
        $signingKey = $rawKey;

        $dn = ['countryName' => 'CH', 'organizationName' => 'phppdf test', 'commonName' => 'phppdf test signer'];
        $csrOptions = ['digest_alg' => 'sha256'];
        if ($config !== null) {
            $csrOptions['config'] = $config;
        }
        $csr = openssl_csr_new($dn, $csrKey, $csrOptions);
        if (!($csr instanceof \OpenSSLCertificateSigningRequest)) {
            throw new \RuntimeException('openssl_csr_new failed: ' . (openssl_error_string() ?: 'unknown'));
        }

        $signOptions = ['digest_alg' => 'sha256'];
        if ($config !== null) {
            $signOptions['config'] = $config;
        }
        $x509 = openssl_csr_sign($csr, null, $signingKey, 365, $signOptions);
        if (!($x509 instanceof \OpenSSLCertificate)) {
            throw new \RuntimeException('openssl_csr_sign failed: ' . (openssl_error_string() ?: 'unknown'));
        }

        $certPemOut = '';
        if (openssl_x509_export($x509, $certPemOut) === false || !is_string($certPemOut)) {
            throw new \RuntimeException('openssl_x509_export failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        $keyPemOut = '';
        $exportKey = $rawKey;
        $pKeyExportOptions = $config !== null ? ['config' => $config] : null;
        if (openssl_pkey_export($exportKey, $keyPemOut, null, $pKeyExportOptions) === false || !is_string($keyPemOut)) {
            throw new \RuntimeException('openssl_pkey_export failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        $p12Out = '';
        $pkcs12Key = $rawKey;
        if (openssl_pkcs12_export($x509, $p12Out, $pkcs12Key, $password) === false || !is_string($p12Out)) {
            throw new \RuntimeException('openssl_pkcs12_export failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        return ['certPem' => $certPemOut, 'keyPem' => $keyPemOut, 'p12' => $p12Out, 'password' => $password];
    }

    /**
     * On Windows, the OPENSSL_CONF environment variable may point to a missing
     * file (e.g. a stale scoop-installed openssl that was removed). In that
     * case, look for the openssl.cnf that ships alongside the running PHP
     * binary (NwAmp keeps it at extras/ssl/openssl.cnf next to php.exe).
     * Passing the config path via the options array works at runtime without
     * requiring environment variable changes.
     *
     * Returns null when the system default should be used (env var is valid or unset).
     */
    private static function resolveOpensslConfig(): ?string
    {
        $confEnv = (string) (getenv('OPENSSL_CONF') ?: '');
        if ($confEnv === '' || file_exists($confEnv)) {
            return null;
        }

        $phpDir = dirname(PHP_BINARY);
        $candidates = [
            $phpDir . '/extras/ssl/openssl.cnf',
            $phpDir . '/../extras/ssl/openssl.cnf',
            $phpDir . '/ssl/openssl.cnf',
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $resolved = realpath($candidate);
                return $resolved !== false ? $resolved : $candidate;
            }
        }
        return null;
    }
}
