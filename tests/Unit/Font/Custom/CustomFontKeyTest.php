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

    public function testFromRegistryKeyRoundTrips(): void
    {
        $key = CustomFontKey::fromRegistryKey('Inter:Inter-Regular');
        self::assertSame('Inter', $key->alias);
        self::assertSame('Inter-Regular', $key->psName);
    }

    public function testFromRegistryKeyKeepsColonsInsidePsName(): void
    {
        $key = CustomFontKey::fromRegistryKey('alias:weird:Name');
        self::assertSame('alias', $key->alias);
        self::assertSame('weird:Name', $key->psName);
    }

    public function testFromRegistryKeyThrowsWhenMissingSeparator(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Invalid custom font registry key: 'no-separator'");
        CustomFontKey::fromRegistryKey('no-separator');
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

    public function testEqualsReturnsTrueOnSameValues(): void
    {
        $a = new CustomFontKey('Inter', 'Inter-Bold');
        $b = new CustomFontKey('Inter', 'Inter-Bold');
        self::assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseOnDifferentAlias(): void
    {
        $a = new CustomFontKey('Inter', 'Inter-Bold');
        $b = new CustomFontKey('Roboto', 'Inter-Bold');
        self::assertFalse($a->equals($b));
    }
}
