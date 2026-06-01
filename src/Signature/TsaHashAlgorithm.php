<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

/**
 * Hash algorithm used for the RFC 3161 messageImprint. SHA-256 is the default;
 * the wider variants exist for TSA policy compliance.
 */
enum TsaHashAlgorithm
{
    case SHA256;
    case SHA384;
    case SHA512;

    public function oid(): string
    {
        return match ($this) {
            self::SHA256 => '2.16.840.1.101.3.4.2.1',
            self::SHA384 => '2.16.840.1.101.3.4.2.2',
            self::SHA512 => '2.16.840.1.101.3.4.2.3',
        };
    }

    public function hashName(): string
    {
        return match ($this) {
            self::SHA256 => 'sha256',
            self::SHA384 => 'sha384',
            self::SHA512 => 'sha512',
        };
    }

    public function digest(string $data): string
    {
        return hash($this->hashName(), $data, true);
    }
}
