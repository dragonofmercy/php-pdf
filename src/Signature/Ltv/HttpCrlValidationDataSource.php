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
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            throw new PdfException("Failed to fetch CRL from {$url}");
        }
        if (preg_match('~-----BEGIN X509 CRL-----~', $body) === 1) {
            return CertificateChain::pemToDer($body, 'X509 CRL');
        }
        return $body;
    }
}
