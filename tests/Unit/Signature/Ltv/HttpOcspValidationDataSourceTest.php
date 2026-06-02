<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Ltv\CertificateChain;
use DragonOfMercy\PhpPdf\Signature\Ltv\HttpOcspValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\OcspClient;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class HttpOcspValidationDataSourceTest extends TestCase
{
    public function testCollectsCertsAndOcspResponse(): void
    {
        $pki = TestPki::issueWithOcsp();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $seenUrl = null;
        $stub = new class ($seenUrl) implements OcspClient {
            public function __construct(public ?string &$seenUrl) {}
            public function request(string $ocspUrl, string $derRequest): string
            {
                $this->seenUrl = $ocspUrl;
                return "\x30\x03canned-ocsp";
            }
        };
        $source = new HttpOcspValidationDataSource($stub);

        $material = $source->collect([$pki['leafPem'], $pki['rootPem']]);

        self::assertSame('http://ocsp.example.com/', $seenUrl);
        self::assertSame([], $material->crls);
        self::assertCount(1, $material->ocsps);
        self::assertSame("\x30\x03canned-ocsp", $material->ocsps[0]);
        self::assertSame(
            [CertificateChain::pemToDer($pki['leafPem']), CertificateChain::pemToDer($pki['rootPem'])],
            $material->certificates,
        );
    }

    public function testThrowsWhenLeafHasNoAia(): void
    {
        $pki = TestPki::issueWithCrl();
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $stub = new class implements OcspClient {
            public function request(string $ocspUrl, string $derRequest): string
            {
                return 'unused';
            }
        };
        $source = new HttpOcspValidationDataSource($stub);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('no OCSP responder URL');
        $source->collect([$pki['leafPem'], $pki['rootPem']]);
    }
}
