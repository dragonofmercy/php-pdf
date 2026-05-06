<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Encryption;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Implements Algorithm 2.B from ISO 32000-2 (PDF 2.0) §7.6.4.3.3.
 * The recursive AES-128-CBC + SHA-256/384/512 password hashing used for R6.
 *
 * @internal
 */
final class PasswordHash
{
    /**
     * @param string $password user or owner password (UTF-8, truncated to 127 bytes)
     * @param string $salt     8-byte validation or key salt
     * @param string $udk      additional input (empty for U derivation, 48-byte U for O derivation)
     * @return string 32-byte hash
     */
    public function hash(string $password, string $salt, string $udk): string
    {
        if (strlen($salt) !== 8) {
            throw new PdfException('Salt must be exactly 8 bytes, got ' . strlen($salt));
        }

        // Truncate password to 127 bytes per ISO 32000-2 §7.6.4.3.2
        $password = substr($password, 0, 127);

        // Initial K = SHA-256(password ∥ salt ∥ udk)
        $k = hash('sha256', $password . $salt . $udk, true);

        $round = 0;
        while (true) {
            // K1 = concat of 64 × (password ∥ K ∥ udk)
            $k1 = str_repeat($password . $k . $udk, 64);

            // E = AES-128-CBC(K[0..15] as key, K[16..31] as IV, K1) — no padding
            $aesKey = substr($k, 0, 16);
            $aesIv = substr($k, 16, 16);
            $e = openssl_encrypt(
                $k1,
                'aes-128-cbc',
                $aesKey,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $aesIv,
            );
            if ($e === false) {
                throw new PdfException('Algorithm 2.B inner AES failed at round ' . $round);
            }

            // Decide next hash function by sum of first 16 bytes of E (as 128-bit BE int) mod 3
            $sumMod3 = self::firstSixteenBytesMod3($e);
            $k = match ($sumMod3) {
                0 => hash('sha256', $e, true),
                1 => hash('sha384', $e, true),
                default => hash('sha512', $e, true),
            };

            // Termination: round >= 64 AND last byte of E <= round - 32
            if ($round >= 64 && ord($e[strlen($e) - 1]) <= $round - 32) {
                break;
            }
            $round++;

            if ($round > 10000) {
                throw new PdfException('Algorithm 2.B runaway loop');
            }
        }

        return substr($k, 0, 32);
    }

    /**
     * Interpret the first 16 bytes of $bytes as a big-endian 128-bit integer
     * and return (integer mod 3). Done manually because PHP ints are 64-bit.
     *
     * Uses fact: 256 mod 3 = 1, so (byte_0 * 256^15 + byte_1 * 256^14 + ...) mod 3
     * simplifies to (sum of bytes) mod 3.
     */
    private static function firstSixteenBytesMod3(string $bytes): int
    {
        $mod = 0;
        for ($i = 0; $i < 16; $i++) {
            $mod = ($mod + ord($bytes[$i])) % 3;
        }
        return $mod;
    }
}
