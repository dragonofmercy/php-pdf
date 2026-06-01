<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Asn1\TimeStampReqBuilder;
use DragonOfMercy\PhpPdf\Signature\Asn1\TimeStampRespParser;

/**
 * Default TsaClient: builds a TimeStampReq, POSTs it to the TSA as
 * application/timestamp-query, and returns the token from the
 * application/timestamp-reply response. Uses curl when available, otherwise a
 * stream context.
 */
final readonly class HttpTsaClient implements TsaClient
{
    public function __construct(
        private string $url,
        private ?TsaBasicAuth $auth,
        private int $timeoutSeconds = 10,
    ) {}

    public function timestamp(string $messageImprint, string $hashOid): string
    {
        if (!str_starts_with($this->url, 'http://') && !str_starts_with($this->url, 'https://')) {
            throw new PdfException("TSA URL must be http(s), got '{$this->url}'");
        }
        $nonce = random_bytes(12);
        $request = TimeStampReqBuilder::build($messageImprint, $hashOid, $nonce);
        $response = $this->post($request);
        return TimeStampRespParser::extractToken($response);
    }

    private function post(string $request): string
    {
        $headers = [
            'Content-Type: application/timestamp-query',
            'Accept: application/timestamp-reply',
        ];
        if ($this->auth !== null) {
            $headers[] = 'Authorization: ' . $this->auth->headerValue();
        }

        if (function_exists('curl_init')) {
            return $this->postCurl($request, $headers);
        }
        return $this->postStream($request, $headers);
    }

    /** @param list<string> $headers */
    private function postCurl(string $request, array $headers): string
    {
        $ch = curl_init($this->url);
        if ($ch === false) {
            throw new PdfException('Failed to initialize curl for TSA request');
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
            throw new PdfException("TSA request failed: {$error}");
        }
        if ($status !== 200) {
            throw new PdfException("TSA returned HTTP {$status}");
        }
        return $body;
    }

    /** @param list<string> $headers */
    private function postStream(string $request, array $headers): string
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $request,
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($this->url, false, $context);
        if ($body === false) {
            throw new PdfException('TSA request failed (stream transport)');
        }
        return $body;
    }
}
