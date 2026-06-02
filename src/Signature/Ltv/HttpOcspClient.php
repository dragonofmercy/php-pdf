<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Default OcspClient: POSTs a DER OCSPRequest as application/ocsp-request and
 * returns the application/ocsp-response body. Uses curl when available,
 * otherwise a stream context. Mirrors HttpTsaClient.
 *
 * @internal
 */
final readonly class HttpOcspClient implements OcspClient
{
    public function __construct(private int $timeoutSeconds = 10) {}

    public function request(string $ocspUrl, string $derRequest): string
    {
        if (!str_starts_with($ocspUrl, 'http://') && !str_starts_with($ocspUrl, 'https://')) {
            throw new PdfException("OCSP URL must be http(s), got '{$ocspUrl}'");
        }
        $headers = [
            'Content-Type: application/ocsp-request',
            'Accept: application/ocsp-response',
        ];
        if (function_exists('curl_init')) {
            return $this->postCurl($ocspUrl, $derRequest, $headers);
        }
        return $this->postStream($ocspUrl, $derRequest, $headers);
    }

    /** @param list<string> $headers */
    private function postCurl(string $url, string $request, array $headers): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new PdfException('Failed to initialize curl for OCSP request');
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new PdfException("OCSP request failed: {$error}");
        }
        if ($status !== 200) {
            throw new PdfException("OCSP responder returned HTTP {$status}");
        }
        return $body;
    }

    /** @param list<string> $headers */
    private function postStream(string $url, string $request, array $headers): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $request,
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            throw new PdfException('OCSP request failed (stream transport)');
        }
        return $body;
    }
}
