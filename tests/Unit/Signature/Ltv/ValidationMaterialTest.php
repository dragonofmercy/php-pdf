<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Signature\Ltv;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Signature\Ltv\ValidationMaterial;
use PHPUnit\Framework\TestCase;

final class ValidationMaterialTest extends TestCase
{
    public function testHoldsCertsAndCrls(): void
    {
        $m = ValidationMaterial::of(['certder'], ['crlder']);
        self::assertSame(['certder'], $m->certificates);
        self::assertSame(['crlder'], $m->crls);
        self::assertSame([], $m->ocsps);
    }

    public function testEmptyCertificateThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('certificate');
        ValidationMaterial::of([''], []);
    }

    public function testMergeDedupes(): void
    {
        $a = ValidationMaterial::of(['c1'], ['r1']);
        $b = ValidationMaterial::of(['c1', 'c2'], ['r2']);
        $merged = $a->merge($b);
        self::assertSame(['c1', 'c2'], $merged->certificates);
        self::assertSame(['r1', 'r2'], $merged->crls);
    }
}
