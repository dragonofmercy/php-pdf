<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Encryption;

use PhpPdf\Encryption\Cipher;
use PhpPdf\Encryption\EncryptionDictBuilder;
use PhpPdf\Encryption\EncryptionKey;
use PhpPdf\Encryption\PasswordHash;
use PHPUnit\Framework\TestCase;

final class EncryptionDictBuilderTest extends TestCase
{
    private function makeKey(bool $encryptMetadata = false, int $permissions = 0b11): EncryptionKey
    {
        return new EncryptionKey(
            userPassword: 'user',
            ownerPassword: 'owner',
            permissions: $permissions,
            encryptMetadata: $encryptMetadata,
            randomSource: fn (int $n) => str_repeat("\x00", $n),
            passwordHash: new PasswordHash(),
            cipher: new Cipher(),
        );
    }

    public function testDictContainsRequiredEntries(): void
    {
        $dict = (new EncryptionDictBuilder())->build($this->makeKey(), $this->makeKey()->u(), false, 0b11);
        $bytes = $dict->toBytes();

        foreach (['/Filter /Standard', '/V 5', '/R 6', '/Length 256', '/StmF /StdCF', '/StrF /StdCF', '/EncryptMetadata'] as $needle) {
            self::assertStringContainsString($needle, $bytes, "Missing: {$needle}");
        }
    }

    public function testCryptFilterSubDict(): void
    {
        $bytes = (new EncryptionDictBuilder())->build($this->makeKey(), 'u', false, 0b11)->toBytes();
        self::assertStringContainsString('/CFM /AESV3', $bytes);
        self::assertStringContainsString('/AuthEvent /DocOpen', $bytes);
    }

    public function testUandOareEmittedAsHexStrings(): void
    {
        $key = $this->makeKey();
        $bytes = (new EncryptionDictBuilder())->build($key, 'u', false, 0b11)->toBytes();
        self::assertSame(1, preg_match('/\/U <[0-9A-F]{96}>/', $bytes));
        self::assertSame(1, preg_match('/\/O <[0-9A-F]{96}>/', $bytes));
        self::assertSame(1, preg_match('/\/UE <[0-9A-F]{64}>/', $bytes));
        self::assertSame(1, preg_match('/\/OE <[0-9A-F]{64}>/', $bytes));
        self::assertSame(1, preg_match('/\/Perms <[0-9A-F]{32}>/', $bytes));
    }

    public function testEncryptMetadataTrueSerializesCorrectly(): void
    {
        $bytes = (new EncryptionDictBuilder())->build($this->makeKey(true), 'u', true, 0b11)->toBytes();
        self::assertStringContainsString('/EncryptMetadata true', $bytes);
    }

    public function testEncryptMetadataFalseSerializesCorrectly(): void
    {
        $bytes = (new EncryptionDictBuilder())->build($this->makeKey(), 'u', false, 0b11)->toBytes();
        self::assertStringContainsString('/EncryptMetadata false', $bytes);
    }

    public function testPermissionsEmittedAsSignedInteger(): void
    {
        $bytes = (new EncryptionDictBuilder())->build($this->makeKey(false, -44), 'u', false, -44)->toBytes();
        self::assertStringContainsString('/P -44', $bytes);
    }
}
