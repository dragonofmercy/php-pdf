<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption;

use DragonOfMercy\PhpPdf\Encryption\Cipher;
use DragonOfMercy\PhpPdf\Encryption\EncryptionKey;
use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use PHPUnit\Framework\TestCase;

final class EncryptionKeyTest extends TestCase
{
    private function zeroSource(): callable
    {
        return fn (int $n): string => str_repeat("\x00", $n);
    }

    public function testFileKeyIs32Bytes(): void
    {
        $ek = new EncryptionKey(
            userPassword: 'user',
            ownerPassword: 'owner',
            permissions: 0xFFFFFFFC,
            encryptMetadata: false,
            randomSource: $this->zeroSource(),
            passwordHash: new PasswordHash(),
            cipher: new Cipher(),
        );
        self::assertSame(32, strlen($ek->fileKey()));
    }

    public function testUIs48Bytes(): void
    {
        $ek = new EncryptionKey(
            userPassword: 'user',
            ownerPassword: 'owner',
            permissions: 0b11,
            encryptMetadata: false,
            randomSource: $this->zeroSource(),
            passwordHash: new PasswordHash(),
            cipher: new Cipher(),
        );
        self::assertSame(48, strlen($ek->u()));
    }

    public function testOIs48Bytes(): void
    {
        $ek = new EncryptionKey(
            userPassword: 'user',
            ownerPassword: 'owner',
            permissions: 0b11,
            encryptMetadata: false,
            randomSource: $this->zeroSource(),
            passwordHash: new PasswordHash(),
            cipher: new Cipher(),
        );
        self::assertSame(48, strlen($ek->o()));
    }

    public function testUeOeAre32Bytes(): void
    {
        $ek = new EncryptionKey(
            userPassword: 'user',
            ownerPassword: 'owner',
            permissions: 0b11,
            encryptMetadata: false,
            randomSource: $this->zeroSource(),
            passwordHash: new PasswordHash(),
            cipher: new Cipher(),
        );
        self::assertSame(32, strlen($ek->ue()));
        self::assertSame(32, strlen($ek->oe()));
    }

    public function testPermsIs16Bytes(): void
    {
        $ek = new EncryptionKey(
            userPassword: 'user',
            ownerPassword: 'owner',
            permissions: 0xFFFFFFFC,
            encryptMetadata: false,
            randomSource: $this->zeroSource(),
            passwordHash: new PasswordHash(),
            cipher: new Cipher(),
        );
        self::assertSame(16, strlen($ek->perms()));
    }

    public function testDeterministicWithFixedRandom(): void
    {
        $make = fn () => new EncryptionKey(
            userPassword: 'user',
            ownerPassword: 'owner',
            permissions: 0b11,
            encryptMetadata: false,
            randomSource: $this->zeroSource(),
            passwordHash: new PasswordHash(),
            cipher: new Cipher(),
        );
        $a = $make();
        $b = $make();
        self::assertSame($a->fileKey(), $b->fileKey());
        self::assertSame($a->u(), $b->u());
        self::assertSame($a->o(), $b->o());
        self::assertSame($a->ue(), $b->ue());
        self::assertSame($a->oe(), $b->oe());
        self::assertSame($a->perms(), $b->perms());
    }

    public function testEncryptMetadataFlagAffectsPerms(): void
    {
        $make = fn (bool $em) => (new EncryptionKey(
            userPassword: 'user',
            ownerPassword: 'owner',
            permissions: 0b11,
            encryptMetadata: $em,
            randomSource: $this->zeroSource(),
            passwordHash: new PasswordHash(),
            cipher: new Cipher(),
        ))->perms();
        self::assertNotSame($make(true), $make(false));
    }
}
