<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption\Reader;

use DragonOfMercy\PhpPdf\Encryption\Reader\Rc4Cipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Rc4CipherTest extends TestCase
{
    /** @return array<string, array{string, string, string}> key, plaintext, ciphertext-hex */
    public static function vectors(): array
    {
        return [
            'Key/Plaintext' => ['Key', 'Plaintext', 'bbf316e8d940af0ad3'],
            'Wiki/pedia'    => ['Wiki', 'pedia', '1021bf0420'],
            'Secret/Attack' => ['Secret', 'Attack at dawn', '45a01f645fc35b383552544b9bf5'],
        ];
    }

    #[DataProvider('vectors')]
    public function testEncryptsToKnownVector(string $key, string $plaintext, string $cipherHex): void
    {
        self::assertSame($cipherHex, bin2hex(Rc4Cipher::apply($key, $plaintext)));
    }

    #[DataProvider('vectors')]
    public function testIsSymmetric(string $key, string $plaintext, string $cipherHex): void
    {
        $cipher = hex2bin($cipherHex);
        self::assertIsString($cipher);
        self::assertSame($plaintext, Rc4Cipher::apply($key, $cipher));
    }
}
