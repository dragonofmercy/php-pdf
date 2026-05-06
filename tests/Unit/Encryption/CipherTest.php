<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption;

use DragonOfMercy\PhpPdf\Encryption\Cipher;
use PHPUnit\Framework\TestCase;

final class CipherTest extends TestCase
{
    public function testAesCbcRoundTrip(): void
    {
        $key = str_repeat("\x01", 32);
        $ivSource = fn (int $n): string => str_repeat("\x00", $n);
        $plaintext = 'Hello, World!';

        $cipher = new Cipher();
        $encrypted = $cipher->encrypt($plaintext, $key, $ivSource);

        self::assertSame(16, strlen(substr($encrypted, 0, 16)));
        self::assertSame(str_repeat("\x00", 16), substr($encrypted, 0, 16));

        $iv = substr($encrypted, 0, 16);
        $ct = substr($encrypted, 16);
        $decrypted = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        self::assertSame($plaintext, $decrypted);
    }

    public function testAesCbcProducesExactBlockAlignedCiphertext(): void
    {
        $key = str_repeat("\xAA", 32);
        $ivSource = fn (int $n): string => str_repeat("\x00", $n);
        $encrypted = (new Cipher())->encrypt('123456789012345', $key, $ivSource);
        self::assertSame(32, strlen($encrypted));
    }

    public function testAesEcbSingleBlock(): void
    {
        $key = str_repeat("\x01", 32);
        $plaintext = str_repeat("\x00", 16);
        $cipher = new Cipher();
        $ciphertext = $cipher->encryptEcb($plaintext, $key);

        self::assertSame(16, strlen($ciphertext));
        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-256-ecb',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        );
        self::assertSame($plaintext, $decrypted);
    }

    public function testEncryptFailureThrows(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        (new Cipher())->encrypt('hello', 'too-short', fn (int $n) => str_repeat("\x00", $n));
    }
}
