<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Signature\Ltv\HttpCrlValidationDataSource;
use DragonOfMercy\PhpPdf\Tests\Support\TestPki;
use PHPUnit\Framework\TestCase;

final class HttpCrlValidationDataSourceTest extends TestCase
{
    public function testFetchesCrlFromFileUrlCdp(): void
    {
        $pki = TestPki::issueWithCrl(); // root + leaf (CDP -> file:// crl) + crl on disk
        if ($pki === null) {
            self::markTestSkipped('openssl CLI unavailable');
        }
        $source = new HttpCrlValidationDataSource();
        $material = $source->collect([$pki['leafPem'], $pki['rootPem']]);
        self::assertCount(2, $material->certificates);
        self::assertCount(1, $material->crls);
    }
}
