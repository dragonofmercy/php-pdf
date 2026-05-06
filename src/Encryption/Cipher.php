<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Encryption;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Thin AES-256 wrapper using ext-openssl.
 *
 * @internal
 */
final class Cipher
{
    /**
     * AES-256-CBC encrypt with PKCS7 padding. Returns IV (16 bytes) ∥ ciphertext.
     *
     * @param callable(int): string $ivSource must return exactly 16 random bytes when called with 16
     */
    public function encrypt(string $plaintext, string $key, callable $ivSource): string
    {
        if (strlen($key) !== 32) {
            throw new PdfException('AES-256 key must be exactly 32 bytes, got ' . strlen($key));
        }
        $iv = $ivSource(16);
        if (strlen($iv) !== 16) {
            throw new PdfException('AES-256-CBC IV must be exactly 16 bytes, got ' . strlen($iv));
        }
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
        );
        if ($ciphertext === false) {
            throw new PdfException('AES-256-CBC encryption failed');
        }
        return $iv . $ciphertext;
    }

    /**
     * AES-256-ECB encrypt single 16-byte block with no padding.
     */
    public function encryptEcb(string $plaintext, string $key): string
    {
        if (strlen($key) !== 32) {
            throw new PdfException('AES-256 key must be exactly 32 bytes, got ' . strlen($key));
        }
        if (strlen($plaintext) % 16 !== 0) {
            throw new PdfException('AES-256-ECB plaintext must be multiple of 16, got ' . strlen($plaintext));
        }
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-ecb',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        if ($ciphertext === false) {
            throw new PdfException('AES-256-ECB encryption failed');
        }
        return $ciphertext;
    }
}
