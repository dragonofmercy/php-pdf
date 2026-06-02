<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Default ValidationDataSource: embeds every certificate in the chain and, for
 * each non-root certificate, fetches the CRL named by its distribution point
 * over HTTP (or file://, via file_get_contents). CRL-only; OCSP is a later
 * phase. Network or parse failures throw rather than silently dropping
 * revocation data.
 *
 * @internal
 */
final class HttpCrlValidationDataSource implements ValidationDataSource
{
    public function collect(array $chainPem): ValidationMaterial
    {
        $certs = [];
        $crls = [];
        foreach ($chainPem as $pem) {
            $certs[] = CertificateChain::pemToDer($pem);
            if (CertificateChain::isSelfSigned($pem)) {
                continue;
            }
            $urls = CertificateChain::crlUrls($pem);
            if ($urls === []) {
                throw new PdfException('Certificate has no CRL distribution point; cannot make it LTV');
            }
            $crls[] = $this->fetchCrlDer($urls[0]);
        }
        return ValidationMaterial::of($certs, $crls);
    }

    private function fetchCrlDer(string $url): string
    {
        $body = @file_get_contents($url);
        if ($body === false || $body === '') {
            throw new PdfException("Failed to fetch CRL from {$url}");
        }
        if (preg_match('~-----BEGIN X509 CRL-----(.+?)-----END X509 CRL-----~s', $body, $m) === 1) {
            $der = base64_decode(preg_replace('~\s+~', '', $m[1]) ?? '', true);
            if ($der === false || $der === '') {
                throw new PdfException("CRL at {$url} did not base64-decode");
            }
            return $der;
        }
        return $body;
    }
}
