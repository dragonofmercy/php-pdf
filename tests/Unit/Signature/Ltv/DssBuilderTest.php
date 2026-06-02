<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Signature\Ltv\DssBuilder;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use PHPUnit\Framework\TestCase;

final class DssBuilderTest extends TestCase
{
    public function testEmitsStreamsAndDssDict(): void
    {
        $material = ValidationMaterial::of(['CERTA', 'CERTB'], ['CRL1']);
        $built = (new DssBuilder())->build($material, 10);

        self::assertCount(4, $built['objects']); // 2 certs + 1 crl + 1 DSS dict
        self::assertSame(10, $built['objects'][0]->objectNumber);
        self::assertSame(13, $built['dssObjectNumber']);

        $dssBytes = $built['objects'][3]->toBytes();
        self::assertStringContainsString('/Certs [10 0 R 11 0 R]', $dssBytes);
        self::assertStringContainsString('/CRLs [12 0 R]', $dssBytes);
        self::assertStringContainsString('CERTA', $built['objects'][0]->toBytes());
    }

    public function testOmitsEmptyCrlArray(): void
    {
        $material = ValidationMaterial::of(['CERTA'], []);
        $built = (new DssBuilder())->build($material, 5);
        self::assertCount(2, $built['objects']); // 1 cert + DSS dict
        $dssBytes = $built['objects'][1]->toBytes();
        self::assertStringContainsString('/Certs [5 0 R]', $dssBytes);
        self::assertStringNotContainsString('/CRLs', $dssBytes);
    }
}
