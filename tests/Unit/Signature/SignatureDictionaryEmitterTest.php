<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DateTimeImmutable;
use DateTimeZone;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SignatureDictionaryEmitter;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class SignatureDictionaryEmitterTest extends TestCase
{
    private function sig(int $max = 16384): Signature
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        return new Signature($cred, 'sig', 'Approved', 'Geneva', 'me@x.com',
            new DateTimeImmutable('2026-05-26 12:00:00', new DateTimeZone('UTC')), $max);
    }

    public function testEmitsSigDictWithPlaceholders(): void
    {
        $obj = (new SignatureDictionaryEmitter())->emit($this->sig(), 42);
        $bytes = $obj->toBytes();
        self::assertStringContainsString('/Type /Sig', $bytes);
        self::assertStringContainsString('/Filter /Adobe.PPKLite', $bytes);
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $bytes);
        self::assertStringContainsString('/ByteRange [0 0000000000 0000000000 0000000000]', $bytes);
        self::assertStringContainsString('/M (D:20260526120000Z)', $bytes);
        self::assertStringContainsString('/Reason (Approved)', $bytes);
        self::assertStringContainsString('/Location (Geneva)', $bytes);
        self::assertStringContainsString('/ContactInfo (me@x.com)', $bytes);
        self::assertMatchesRegularExpression('~/Contents <0{32768}>~', $bytes);
        self::assertStringStartsWith('42 0 obj', $bytes);
    }

    public function testOmitsOptionalMetadataWhenNull(): void
    {
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        $sig = new Signature($cred, 'sig', null, null, null,
            new DateTimeImmutable('2026-05-26 12:00:00', new DateTimeZone('UTC')), 8192);
        $bytes = (new SignatureDictionaryEmitter())->emit($sig, 7)->toBytes();
        self::assertStringNotContainsString('/Reason', $bytes);
        self::assertStringNotContainsString('/Location', $bytes);
        self::assertStringNotContainsString('/ContactInfo', $bytes);
        self::assertMatchesRegularExpression('~/Contents <0{16384}>~', $bytes);
    }
}
