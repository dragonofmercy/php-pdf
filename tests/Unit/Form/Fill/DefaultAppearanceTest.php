<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Fill;

use DragonOfMercy\PhpPdf\Form\Fill\DefaultAppearance;
use PHPUnit\Framework\TestCase;

final class DefaultAppearanceTest extends TestCase
{
    public function testParsesColorFontSize(): void
    {
        $da = DefaultAppearance::parse('0 g /Helv 12 Tf');
        self::assertSame('Helv', $da->fontAlias);
        self::assertSame(12.0, $da->size);
        self::assertSame('0 g', $da->colorOps);
        self::assertFalse($da->isAutoSize());
    }

    public function testRgbColorAndAutoSize(): void
    {
        $da = DefaultAppearance::parse('1 0 0 rg /TiRo 0 Tf');
        self::assertSame('TiRo', $da->fontAlias);
        self::assertSame(0.0, $da->size);
        self::assertSame('1 0 0 rg', $da->colorOps);
        self::assertTrue($da->isAutoSize());
    }

    public function testDefaultsWhenEmpty(): void
    {
        $da = DefaultAppearance::parse('');
        self::assertSame('Helv', $da->fontAlias);
        self::assertSame(0.0, $da->size);
        self::assertSame('0 g', $da->colorOps);
    }

    public function testNoColorOpsDefaultsToBlack(): void
    {
        $da = DefaultAppearance::parse('/Helv 10 Tf');
        self::assertSame('Helv', $da->fontAlias);
        self::assertSame(10.0, $da->size);
        self::assertSame('0 g', $da->colorOps);
    }

    public function testFractionalSize(): void
    {
        $da = DefaultAppearance::parse('0.5 0.5 0.5 rg /Cour 8.5 Tf');
        self::assertSame('Cour', $da->fontAlias);
        self::assertSame(8.5, $da->size);
        self::assertSame('0.5 0.5 0.5 rg', $da->colorOps);
    }

    public function testExtraWhitespaceTolerated(): void
    {
        $da = DefaultAppearance::parse('   0 g    /Helv   11   Tf  ');
        self::assertSame('Helv', $da->fontAlias);
        self::assertSame(11.0, $da->size);
        self::assertSame('0 g', $da->colorOps);
    }
}
