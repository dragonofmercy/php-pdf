<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption\Reader;

use DragonOfMercy\PhpPdf\Encryption\Reader\AesCbcDecryptor;
use DragonOfMercy\PhpPdf\Encryption\Reader\AesCbcEncryptor;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class AesCbcEncryptorTest extends TestCase
{
    /** @return callable(int): string */
    private function iv(): callable { return static fn (int $n): string => str_repeat("\x04", $n); }

    public function testRoundTrips128(): void
    {
        $key = random_bytes(16);
        $plain = 'edit payload for aes-128';
        $ct = AesCbcEncryptor::encrypt($key, $plain, $this->iv());
        self::assertSame($plain, AesCbcDecryptor::decrypt($key, $ct));
    }

    public function testRoundTrips256AndFullPadBlock(): void
    {
        $key = random_bytes(32);
        $plain = str_repeat('B', 16); // exactly one block -> PKCS#7 adds a full pad block
        $ct = AesCbcEncryptor::encrypt($key, $plain, $this->iv());
        self::assertSame(16 + 16 + 16, strlen($ct)); // IV + 1 data block + 1 pad block
        self::assertSame($plain, AesCbcDecryptor::decrypt($key, $ct));
    }

    public function testRejectsBadKeyLength(): void
    {
        $this->expectException(PdfException::class);
        AesCbcEncryptor::encrypt(random_bytes(24), 'x', $this->iv());
    }

    public function testRejectsBadIvLength(): void
    {
        $this->expectException(PdfException::class);
        AesCbcEncryptor::encrypt(random_bytes(16), 'x', static fn (int $n): string => 'short');
    }
}
