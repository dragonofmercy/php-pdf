<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Configures RFC 3161 timestamping for a signature. Build with Tsa::http() for
 * the default HTTP transport, or Tsa::withClient() to inject a TsaClient
 * (tests, custom transports). The `client` performs the round-trip and `hash`
 * (the imprint algorithm) is consumed by SignatureTimestamper.
 */
final readonly class Tsa
{
    private function __construct(
        public TsaHashAlgorithm $hash,
        public TsaClient $client,
    ) {}

    public static function http(
        string $url,
        ?TsaBasicAuth $auth = null,
        TsaHashAlgorithm $hash = TsaHashAlgorithm::SHA256,
    ): self {
        if ($url === '') {
            throw new PdfException('TSA URL cannot be empty');
        }
        return new self($hash, new HttpTsaClient($url, $auth));
    }

    public static function withClient(
        TsaClient $client,
        TsaHashAlgorithm $hash = TsaHashAlgorithm::SHA256,
    ): self {
        return new self($hash, $client);
    }
}
