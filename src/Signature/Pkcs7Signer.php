<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Produces a detached PKCS#7 / CMS signature (DER) over arbitrary bytes using
 * openssl_cms_sign with SHA-256 signed attributes. The CA chain from the
 * credential is embedded. Temp files are removed in a finally block.
 */
final readonly class Pkcs7Signer
{
    public function sign(string $data, SigningCertificate $certificate): string
    {
        $in = tempnam(sys_get_temp_dir(), 'pps_in');
        $out = tempnam(sys_get_temp_dir(), 'pps_out');
        if ($in === false || $out === false) {
            if ($in !== false) {
                @unlink($in);
            }
            if ($out !== false) {
                @unlink($out);
            }
            throw new PdfException('Failed to allocate temp files for signing');
        }
        $extra = null;
        try {
            if (file_put_contents($in, $data) === false) {
                throw new PdfException('Failed to write signing input temp file');
            }

            $cert = openssl_x509_read($certificate->certificatePem);
            if ($cert === false) {
                throw new PdfException(
                    'openssl_x509_read failed: ' . (openssl_error_string() ?: 'unknown openssl error'),
                );
            }

            $privateKey = openssl_pkey_get_private($certificate->privateKeyPem);
            if ($privateKey === false) {
                throw new PdfException(
                    'openssl_pkey_get_private failed: ' . (openssl_error_string() ?: 'unknown openssl error'),
                );
            }

            $extraCertsFile = null;
            if ($certificate->extraCertificates !== []) {
                $extra = tempnam(sys_get_temp_dir(), 'pps_chain');
                if ($extra === false) {
                    throw new PdfException('Failed to allocate chain temp file');
                }
                if (file_put_contents($extra, implode("\n", $certificate->extraCertificates)) === false) {
                    throw new PdfException('Failed to write certificate chain temp file');
                }
                $extraCertsFile = $extra;
            }

            $ok = openssl_cms_sign(
                $in,
                $out,
                $cert,
                $privateKey,
                [],
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
                $extraCertsFile,
            );
            if ($ok === false) {
                throw new PdfException(
                    'openssl_cms_sign failed: ' . (openssl_error_string() ?: 'unknown openssl error'),
                );
            }
            $der = file_get_contents($out);
            if ($der === false || $der === '') {
                throw new PdfException('openssl_cms_sign produced no output');
            }
            return $der;
        } finally {
            @unlink($in);
            @unlink($out);
            if (is_string($extra)) {
                @unlink($extra);
            }
        }
    }
}
