<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Support;

use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class TestPkiOcspTest extends TestCase
{
    public function testIssueWithOcspProducesCertsAndResponse(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        self::assertStringContainsString('BEGIN CERTIFICATE', $pki['rootPem']);
        self::assertStringContainsString('BEGIN CERTIFICATE', $pki['leafPem']);
        self::assertStringContainsString('BEGIN CERTIFICATE', $pki['ocspSignerPem']);
        self::assertNotSame('', $pki['leafP12']);
        self::assertNotSame('', $pki['ocspResponseDer']);
        self::assertSame(0x30, ord($pki['ocspResponseDer'][0]));
    }
}
