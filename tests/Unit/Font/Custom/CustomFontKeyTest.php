<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Font\Custom;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font\Custom\CustomFontKey;
use PHPUnit\Framework\TestCase;

final class CustomFontKeyTest extends TestCase
{
    public function testConstructorExposesAliasAndPsName(): void
    {
        $key = new CustomFontKey('Inter', 'Inter-Regular');
        self::assertSame('Inter', $key->alias);
        self::assertSame('Inter-Regular', $key->psName);
    }

    public function testToRegistryKeyJoinsWithColon(): void
    {
        $key = new CustomFontKey('Inter', 'Inter-Bold');
        self::assertSame('Inter:Inter-Bold', $key->toRegistryKey());
    }

    public function testConstructorThrowsOnEmptyAlias(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('CustomFontKey alias and psName cannot be empty');
        new CustomFontKey('', 'Inter-Regular');
    }

    public function testConstructorThrowsOnEmptyPsName(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('CustomFontKey alias and psName cannot be empty');
        new CustomFontKey('Inter', '');
    }
}
