<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Configures RFC 3161 timestamping for a signature. Build with Tsa::http() for
 * the default HTTP transport, or Tsa::withClient() to inject a TsaClient
 * (tests, custom transports). The resolved client and the hash algorithm are
 * consumed by SignatureTimestamper.
 */
final readonly class Tsa
{
    private function __construct(
        public string $url,
        public ?TsaBasicAuth $auth,
        public TsaHashAlgorithm $hash,
        private ?TsaClient $client,
    ) {}

    public static function http(
        string $url,
        ?TsaBasicAuth $auth = null,
        TsaHashAlgorithm $hash = TsaHashAlgorithm::SHA256,
    ): self {
        if ($url === '') {
            throw new PdfException('TSA URL cannot be empty');
        }
        return new self($url, $auth, $hash, null);
    }

    public static function withClient(
        TsaClient $client,
        TsaHashAlgorithm $hash = TsaHashAlgorithm::SHA256,
    ): self {
        return new self('', null, $hash, $client);
    }

    public function resolveClient(): TsaClient
    {
        return $this->client ?? new HttpTsaClient($this->url, $this->auth, $this->hash);
    }
}
