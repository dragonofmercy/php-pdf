<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Encryption\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Pure-PHP RC4 stream cipher (KSA + PRGA). Symmetric: apply() both encrypts and
 * decrypts. Used only to READ legacy RC4-encrypted PDFs; the library does not
 * generate RC4. Implemented in PHP rather than via openssl because the RC4
 * cipher is disabled in many openssl builds.
 *
 * @internal
 */
final class Rc4Cipher
{
    public static function apply(string $key, string $data): string
    {
        $keyLen = strlen($key);
        if ($keyLen === 0) {
            throw new PdfException('RC4 key cannot be empty');
        }
        $s = range(0, 255);
        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLen])) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $out = '';
        $i = 0;
        $j = 0;
        $len = strlen($data);
        for ($n = 0; $n < $len; $n++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $s[$i]) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr((ord($data[$n]) ^ $s[($s[$i] + $s[$j]) & 0xFF]) & 0xFF);
        }
        return $out;
    }
}
