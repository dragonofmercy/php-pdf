<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Encryption\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * AES-CBC decryption for the PDF Standard security handler. The 16-byte IV is
 * the first 16 bytes of the data; the rest is ciphertext. The cipher is chosen
 * by key length (16 -> AES-128, 32 -> AES-256). PKCS#7 padding is stripped.
 *
 * @internal
 */
final class AesCbcDecryptor
{
    public static function decrypt(string $key, string $data): string
    {
        $cipher = match (strlen($key)) {
            16 => 'aes-128-cbc',
            32 => 'aes-256-cbc',
            default => throw new PdfException('AES key must be 16 or 32 bytes, got ' . strlen($key)),
        };
        if (strlen($data) < 16) {
            throw new PdfException('AES-CBC data shorter than the 16-byte IV');
        }
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        if ($ciphertext === '') {
            return '';
        }
        $plain = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($plain === false) {
            throw new PdfException('AES-CBC decryption failed');
        }
        return self::stripPkcs7($plain);
    }

    private static function stripPkcs7(string $plain): string
    {
        $len = strlen($plain);
        if ($len === 0 || $len % 16 !== 0) {
            return $plain; // not block-aligned: leave as-is (defensive)
        }
        $pad = ord($plain[$len - 1]);
        if ($pad < 1 || $pad > 16 || $pad > $len) {
            return $plain;
        }
        if (substr($plain, $len - $pad) !== str_repeat(chr($pad), $pad)) {
            return $plain;
        }
        return substr($plain, 0, $len - $pad);
    }
}
