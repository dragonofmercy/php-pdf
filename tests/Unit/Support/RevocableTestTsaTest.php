<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Support;

use DragonOfMercy\PhpPdf\Tests\Support\RevocableTestTsa;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class RevocableTestTsaTest extends TestCase
{
    public function testProducesRfc3161TokenEmbeddingTsaCert(): void
    {
        $pki = TestPki::issueTsaWithCrl();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $tsa = new RevocableTestTsa($pki['dir']);
        $imprint = hash('sha256', 'content to timestamp', true);

        $token = $tsa->timestamp($imprint, '2.16.840.1.101.3.4.2.1');

        self::assertSame(0x30, ord($token[0]));
        $tstInfoOid = hex2bin('060b2a864886f70d0109100104');
        self::assertIsString($tstInfoOid);
        self::assertStringContainsString($tstInfoOid, $token);
        self::assertStringContainsString('phppdf blta tsa', $token);
    }
}
