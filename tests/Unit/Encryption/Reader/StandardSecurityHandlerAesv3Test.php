<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption\Reader;

use DragonOfMercy\PhpPdf\Encryption\Cipher;
use DragonOfMercy\PhpPdf\Encryption\EncryptionKey;
use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use DragonOfMercy\PhpPdf\Encryption\Reader\EncryptionParams;
use DragonOfMercy\PhpPdf\Encryption\Reader\StandardSecurityHandler;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class StandardSecurityHandlerAesv3Test extends TestCase
{
    /** @return array{EncryptionParams, PasswordHash, string} params, passwordHash, expectedFileKey */
    private function build(string $userPwd, string $ownerPwd): array
    {
        $ph = new PasswordHash();
        $seed = 12345;
        $rand = function (int $n) use (&$seed): string {
            $s = '';
            for ($i = 0; $i < $n; $i++) { $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF; $s .= chr($seed & 0xFF); }
            return $s;
        };
        $key = new EncryptionKey($userPwd, $ownerPwd, -4, true, $rand, $ph, new Cipher());
        $params = EncryptionParams::forAesv3($key->o(), $key->u(), $key->oe(), $key->ue(), -4, true, 'ID0');
        return [$params, $ph, $key->fileKey()];
    }

    public function testEmptyUserPasswordRecoversFileKey(): void
    {
        [$params, $ph, $fileKey] = $this->build('', 'owner');
        self::assertSame($fileKey, (new StandardSecurityHandler($params, $ph))->authenticate(null)->fileKey());
    }

    public function testUserPasswordRecoversFileKey(): void
    {
        [$params, $ph, $fileKey] = $this->build('userpw', 'owner');
        self::assertSame($fileKey, (new StandardSecurityHandler($params, $ph))->authenticate('userpw')->fileKey());
    }

    public function testOwnerPasswordRecoversFileKey(): void
    {
        [$params, $ph, $fileKey] = $this->build('userpw', 'ownerpw');
        self::assertSame($fileKey, (new StandardSecurityHandler($params, $ph))->authenticate('ownerpw')->fileKey());
    }

    public function testWrongPasswordThrows(): void
    {
        [$params, $ph] = $this->build('userpw', 'ownerpw');
        $this->expectException(PdfException::class);
        (new StandardSecurityHandler($params, $ph))->authenticate('nope');
    }

    public function testFileKeyBeforeAuthThrows(): void
    {
        [$params, $ph] = $this->build('', 'owner');
        $this->expectException(PdfException::class);
        (new StandardSecurityHandler($params, $ph))->fileKey();
    }
}
