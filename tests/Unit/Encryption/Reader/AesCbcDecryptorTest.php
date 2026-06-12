<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption\Reader;

use DragonOfMercy\PhpPdf\Encryption\Reader\AesCbcDecryptor;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class AesCbcDecryptorTest extends TestCase
{
    public function testDecryptsAes128(): void
    {
        $key = random_bytes(16);
        $iv = random_bytes(16);
        $plain = 'hello aes-128 world';
        $enc = openssl_encrypt($plain, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);
        self::assertIsString($enc);
        self::assertSame($plain, AesCbcDecryptor::decrypt($key, $iv . $enc));
    }

    public function testDecryptsAes256AndStripsFullPadBlock(): void
    {
        $key = random_bytes(32);
        $iv = random_bytes(16);
        $plain = str_repeat('A', 16); // exactly one block -> PKCS#7 adds a full 0x10 block
        $enc = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        self::assertIsString($enc);
        self::assertSame($plain, AesCbcDecryptor::decrypt($key, $iv . $enc));
    }

    public function testRejectsTooShort(): void
    {
        $this->expectException(PdfException::class);
        AesCbcDecryptor::decrypt(random_bytes(16), random_bytes(8)); // < 16-byte IV
    }

    public function testRejectsBadKeyLength(): void
    {
        $this->expectException(PdfException::class);
        AesCbcDecryptor::decrypt(random_bytes(24), random_bytes(32));
    }
}
