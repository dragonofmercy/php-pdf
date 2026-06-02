<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Signature\Ltv\StaticValidationDataSource;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use PHPUnit\Framework\TestCase;

final class StaticValidationDataSourceTest extends TestCase
{
    public function testReturnsTheInjectedMaterialRegardlessOfChain(): void
    {
        $material = ValidationMaterial::of(['cder'], ['rder']);
        $source = new StaticValidationDataSource($material);
        self::assertSame($material, $source->collect(['-----BEGIN CERTIFICATE-----...']));
    }
}
