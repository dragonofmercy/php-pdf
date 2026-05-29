<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\SvgFontResolver;
use PHPUnit\Framework\TestCase;

final class SvgFontResolverTest extends TestCase
{
    /** @return array<string, string> lowercased alias => actual alias */
    private function aliases(string ...$names): array
    {
        $map = [];
        foreach ($names as $n) {
            $map[strtolower($n)] = $n;
        }
        return $map;
    }

    public function testRegisteredCustomAliasWins(): void
    {
        $font = SvgFontResolver::resolve('FS', false, false, $this->aliases('FS'));
        self::assertTrue($font->isCustom());
        self::assertSame('FS', $font->customAlias());
    }

    public function testCustomMatchIsCaseInsensitive(): void
    {
        $font = SvgFontResolver::resolve('fs', false, false, $this->aliases('FS'));
        self::assertTrue($font->isCustom());
        self::assertSame('FS', $font->customAlias());
    }

    public function testSecondTokenMatchesWhenFirstUnknown(): void
    {
        $font = SvgFontResolver::resolve('Unknown, FS', false, false, $this->aliases('FS'));
        self::assertTrue($font->isCustom());
        self::assertSame('FS', $font->customAlias());
    }

    public function testCustomCarriesBoldItalicFlags(): void
    {
        $font = SvgFontResolver::resolve('FS', true, true, $this->aliases('FS'));
        self::assertTrue($font->isCustom());
        self::assertTrue($font->isBold());
        self::assertTrue($font->isItalic());
    }

    public function testStandardKeywordWhenNoCustomMatch(): void
    {
        $font = SvgFontResolver::resolve('serif', false, false, $this->aliases('FS'));
        self::assertFalse($font->isCustom());
        self::assertStringStartsWith('Times', $font->pdfName());
    }

    public function testHelveticaFallbackForUnknownFamily(): void
    {
        $font = SvgFontResolver::resolve('Nope', false, false, []);
        self::assertFalse($font->isCustom());
        self::assertStringStartsWith('Helvetica', $font->pdfName());
    }

    public function testMonospaceKeywordResolvesToCourier(): void
    {
        $font = SvgFontResolver::resolve('monospace', false, false, []);
        self::assertFalse($font->isCustom());
        self::assertStringStartsWith('Courier', $font->pdfName());
    }

    public function testBoldItalicCompositionOnStandardFamily(): void
    {
        $font = SvgFontResolver::resolve('Georgia', true, true, []);
        self::assertFalse($font->isCustom());
        self::assertSame('Times-BoldItalic', $font->pdfName());
    }

    public function testQuotedFirstRecognizedTokenWins(): void
    {
        $font = SvgFontResolver::resolve('"Foo Bar", Consolas, sans-serif', false, false, []);
        self::assertFalse($font->isCustom());
        self::assertStringStartsWith('Courier', $font->pdfName());
    }
}
