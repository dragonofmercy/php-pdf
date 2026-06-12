<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Encryption\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * AES-CBC encryption for re-encrypting the new objects of an edited encrypted
 * PDF's incremental revision. The inverse of AesCbcDecryptor: a random 16-byte
 * IV is prepended to the PKCS#7-padded ciphertext. Cipher is chosen by key
 * length (16 -> AES-128, 32 -> AES-256). Separate from the generation-side
 * Cipher (AES-256 only, byte-identity-critical) so editing legacy AES-128 files
 * stays isolated.
 *
 * @internal
 */
final class AesCbcEncryptor
{
    /**
     * @param callable(int): string $ivSource
     */
    public static function encrypt(string $key, string $plaintext, callable $ivSource): string
    {
        $cipher = match (strlen($key)) {
            16 => 'aes-128-cbc',
            32 => 'aes-256-cbc',
            default => throw new PdfException('AES key must be 16 or 32 bytes, got ' . strlen($key)),
        };
        $iv = $ivSource(16);
        if (strlen($iv) !== 16) {
            throw new PdfException('AES-CBC IV must be exactly 16 bytes, got ' . strlen($iv));
        }
        // OPENSSL_RAW_DATA without ZERO_PADDING -> openssl applies PKCS#7 padding.
        $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new PdfException('AES-CBC encryption failed');
        }
        return $iv . $ciphertext;
    }
}
