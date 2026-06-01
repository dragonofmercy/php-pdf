<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature;

use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Signature\AppendedDocumentTimestamp;
use DragonOfMercy\PhpPdf\Signature\AppendedSignature;
use DragonOfMercy\PhpPdf\Signature\DocumentTimestamp;
use DragonOfMercy\PhpPdf\Signature\Signature;
use DragonOfMercy\PhpPdf\Signature\SigningCertificate;
use DragonOfMercy\PhpPdf\Signature\Tsa;
use DragonOfMercy\PhpPdf\Signature\TsaClient;
use DragonOfMercy\PhpPdf\Tests\Support\TestCertificate;
use PHPUnit\Framework\TestCase;

final class AppendedRevisionTest extends TestCase
{
    public function testAppendedSignatureExposesFieldNameValueDictAndFill(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            self::markTestSkipped('openssl unavailable');
        }
        $gen = TestCertificate::generate();
        $cred = SigningCertificate::fromPkcs12Bytes($gen['p12'], $gen['password']);
        $sig = new Signature($cred, 'Signature2', 'Reviewed', null, null, new DateTimeImmutable(), 16384, null);
        $rev = new AppendedSignature($sig);

        self::assertSame('Signature2', $rev->fieldName());
        self::assertSame(16384, $rev->maxSignatureBytes());
        $dict = $rev->valueDict(9)->toBytes();
        self::assertStringContainsString('/Type /Sig', $dict);
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $dict);
        self::assertStringContainsString('/Reason (Reviewed)', $dict);
        $der = $rev->fill('hello');
        self::assertNotSame('', $der);
    }

    public function testAppendedDocumentTimestampExposesFieldNameValueDictAndFill(): void
    {
        $token = "\x01\x02\x03";
        $stub = new class ($token) implements TsaClient {
            public function __construct(private string $token) {}
            public function timestamp(string $messageImprint, string $hashOid): string
            {
                return $this->token;
            }
        };
        $dt = new DocumentTimestamp(Tsa::withClient($stub), 8192);
        $rev = new AppendedDocumentTimestamp($dt, 'DocTimeStamp3');

        self::assertSame('DocTimeStamp3', $rev->fieldName());
        self::assertSame(8192, $rev->maxSignatureBytes());
        self::assertStringContainsString('/Type /DocTimeStamp', $rev->valueDict(4)->toBytes());
        self::assertSame($token, $rev->fill('data'));
    }
}
