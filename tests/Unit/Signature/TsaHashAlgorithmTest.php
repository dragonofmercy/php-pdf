<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\TsaBasicAuth;
use DragonOfMercy\PhpPdf\Signature\TsaHashAlgorithm;
use PHPUnit\Framework\TestCase;

final class TsaHashAlgorithmTest extends TestCase
{
    public function testOidsAndHashNames(): void
    {
        self::assertSame('2.16.840.1.101.3.4.2.1', TsaHashAlgorithm::SHA256->oid());
        self::assertSame('sha256', TsaHashAlgorithm::SHA256->hashName());
        self::assertSame('2.16.840.1.101.3.4.2.2', TsaHashAlgorithm::SHA384->oid());
        self::assertSame('sha384', TsaHashAlgorithm::SHA384->hashName());
        self::assertSame('2.16.840.1.101.3.4.2.3', TsaHashAlgorithm::SHA512->oid());
        self::assertSame('sha512', TsaHashAlgorithm::SHA512->hashName());
    }

    public function testDigestProducesRawBytes(): void
    {
        $digest = TsaHashAlgorithm::SHA256->digest('abc');
        self::assertSame(hash('sha256', 'abc', true), $digest);
        self::assertSame(32, strlen($digest));
    }

    public function testBasicAuthHeaderValue(): void
    {
        $auth = new TsaBasicAuth('user', 'pass');
        self::assertSame('Basic ' . base64_encode('user:pass'), $auth->headerValue());
    }

    public function testBasicAuthRejectsEmptyUsername(): void
    {
        $this->expectException(PdfException::class);
        new TsaBasicAuth('', 'pass');
    }
}
