<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Encryption\Reader;

use DragonOfMercy\PhpPdf\Encryption\PasswordHash;
use DragonOfMercy\PhpPdf\Encryption\Reader\EncryptionParams;
use DragonOfMercy\PhpPdf\Encryption\Reader\Rc4Cipher;
use DragonOfMercy\PhpPdf\Encryption\Reader\StandardSecurityHandler;
use DragonOfMercy\PhpPdf\Encryption\Reader\StreamCipher;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

/**
 * Self-consistent anchor for the legacy RC4 / AES-128 (R2-R4) Algorithm-2 key
 * derivation and authentication. The test re-implements the ISO 32000-1
 * generation side (Algorithms 2/3/4/5) to produce /O and /U, then asserts the
 * handler recovers a file key and authenticates the empty/user/owner roles and
 * rejects a wrong password. External RC4 fixtures (qpdf / pikepdf) arrive in a
 * later task as the definitive cross-check.
 */
final class StandardSecurityHandlerRc4Test extends TestCase
{
    private const string PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private function padPassword(string $pw): string
    {
        return substr($pw . self::PAD, 0, 32);
    }

    /** Algorithm 2: file key from the user password. */
    private function alg2FileKey(string $userPwd, int $r, string $o, int $p, int $keyLen, bool $encryptMetadata, string $idFirst): string
    {
        $input = $this->padPassword($userPwd) . $o . pack('V', $p & 0xFFFFFFFF) . $idFirst;
        if ($r >= 4 && !$encryptMetadata) {
            $input .= "\xFF\xFF\xFF\xFF";
        }
        $key = md5($input, true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $key = md5(substr($key, 0, $keyLen), true);
            }
        }
        return substr($key, 0, $keyLen);
    }

    /** Algorithm 3: compute /O from owner + user passwords. */
    private function computeO(string $ownerPwd, string $userPwd, int $r, int $keyLen): string
    {
        $rc4key = md5($this->padPassword($ownerPwd), true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $rc4key = md5(substr($rc4key, 0, $keyLen), true);
            }
        }
        $rc4key = substr($rc4key, 0, $keyLen);
        $o = Rc4Cipher::apply($rc4key, $this->padPassword($userPwd));
        if ($r >= 3) {
            for ($i = 1; $i <= 19; $i++) {
                $rk = '';
                foreach (str_split($rc4key) as $b) {
                    $rk .= chr((ord($b) ^ $i) & 0xFF);
                }
                $o = Rc4Cipher::apply($rk, $o);
            }
        }
        return $o;
    }

    /** Algorithm 4/5: compute /U from the file key. */
    private function computeU(string $fileKey, int $r, string $idFirst): string
    {
        if ($r === 2) {
            return Rc4Cipher::apply($fileKey, self::PAD);
        }
        $h = md5(self::PAD . $idFirst, true);
        $x = Rc4Cipher::apply($fileKey, $h);
        for ($i = 1; $i <= 19; $i++) {
            $rk = '';
            foreach (str_split($fileKey) as $b) {
                $rk .= chr((ord($b) ^ $i) & 0xFF);
            }
            $x = Rc4Cipher::apply($rk, $x);
        }
        // Stored /U is 32 bytes for R3/R4: pad the 16-byte result with arbitrary
        // trailing bytes (the handler only compares the first 16).
        return $x . substr(self::PAD, 0, 16);
    }

    /**
     * @return array{EncryptionParams, string} params, expectedFileKey
     */
    private function build(string $userPwd, string $ownerPwd, int $r, int $keyLen, StreamCipher $cipher): array
    {
        $p = -4;
        $encryptMetadata = true;
        $idFirst = 'ID0_sixteen_byte';
        $o = $this->computeO($ownerPwd, $userPwd, $r, $keyLen);
        $fileKey = $this->alg2FileKey($userPwd, $r, $o, $p, $keyLen, $encryptMetadata, $idFirst);
        $u = $this->computeU($fileKey, $r, $idFirst);
        $params = EncryptionParams::forRc4($r, $o, $u, $p, $keyLen, $encryptMetadata, $idFirst, $cipher);
        return [$params, $fileKey];
    }

    private function handler(EncryptionParams $params): StandardSecurityHandler
    {
        return new StandardSecurityHandler($params, new PasswordHash());
    }

    public function testR3Rc4EmptyUserPasswordRecoversFileKey(): void
    {
        [$params, $fileKey] = $this->build('', 'owner', 3, 16, StreamCipher::Rc4);
        self::assertSame($fileKey, $this->handler($params)->authenticate(null)->fileKey());
    }

    public function testR3Rc4UserPasswordRecoversFileKey(): void
    {
        [$params, $fileKey] = $this->build('userpw', 'owner', 3, 16, StreamCipher::Rc4);
        self::assertSame($fileKey, $this->handler($params)->authenticate('userpw')->fileKey());
    }

    public function testR3Rc4OwnerPasswordRecoversFileKey(): void
    {
        [$params, $fileKey] = $this->build('userpw', 'ownerpw', 3, 16, StreamCipher::Rc4);
        self::assertSame($fileKey, $this->handler($params)->authenticate('ownerpw')->fileKey());
    }

    public function testR3Rc4EmptyUserWithOwnerRecoversFileKey(): void
    {
        [$params, $fileKey] = $this->build('', 'ownerpw', 3, 16, StreamCipher::Rc4);
        self::assertSame($fileKey, $this->handler($params)->authenticate('ownerpw')->fileKey());
    }

    public function testR4Aesv2EmptyUserPasswordRecoversFileKey(): void
    {
        [$params, $fileKey] = $this->build('', 'owner', 4, 16, StreamCipher::Aesv2);
        self::assertSame($fileKey, $this->handler($params)->authenticate(null)->fileKey());
    }

    public function testR4Aesv2UserPasswordRecoversFileKey(): void
    {
        [$params, $fileKey] = $this->build('userpw', 'owner', 4, 16, StreamCipher::Aesv2);
        self::assertSame($fileKey, $this->handler($params)->authenticate('userpw')->fileKey());
    }

    public function testR4Aesv2OwnerPasswordRecoversFileKey(): void
    {
        [$params, $fileKey] = $this->build('userpw', 'ownerpw', 4, 16, StreamCipher::Aesv2);
        self::assertSame($fileKey, $this->handler($params)->authenticate('ownerpw')->fileKey());
    }

    public function testR2Rc4UserPasswordRecoversFileKey(): void
    {
        [$params, $fileKey] = $this->build('userpw', 'ownerpw', 2, 5, StreamCipher::Rc4);
        self::assertSame($fileKey, $this->handler($params)->authenticate('userpw')->fileKey());
    }

    public function testWrongPasswordThrows(): void
    {
        [$params] = $this->build('userpw', 'ownerpw', 4, 16, StreamCipher::Aesv2);
        $this->expectException(PdfException::class);
        $this->handler($params)->authenticate('nope');
    }

    public function testPaddingConstantIsExact(): void
    {
        // Behavioral check: a params built with the exact PAD constant above
        // authenticates with the empty password. If the handler's PAD differed,
        // its /U recomputation would not match, so this implies the constant.
        [$params, $fileKey] = $this->build('', 'owner', 3, 16, StreamCipher::Rc4);
        self::assertSame(
            '28bf4e5e4e758a4164004e56fffa01082e2e00b6d0683e802f0ca9fe6453697a',
            bin2hex(self::PAD),
        );
        self::assertSame($fileKey, $this->handler($params)->authenticate(null)->fileKey());
    }
}
