<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\SvgFontResolver;
use PHPUnit\Framework\TestCase;

final class SvgFontResolverTest extends TestCase
{
    public function testGenericSansSerifMapsToHelvetica(): void
    {
        self::assertSame('Helvetica', SvgFontResolver::resolve('sans-serif', false, false)->pdfName());
    }

    public function testSerifMapsToTimesRoman(): void
    {
        self::assertSame('Times-Roman', SvgFontResolver::resolve('serif', false, false)->pdfName());
    }

    public function testMonospaceMapsToCourier(): void
    {
        self::assertSame('Courier', SvgFontResolver::resolve('monospace', false, false)->pdfName());
    }

    public function testBoldItalicComposes(): void
    {
        self::assertSame('Times-BoldItalic', SvgFontResolver::resolve('Georgia', true, true)->pdfName());
    }

    public function testFirstRecognisedTokenWinsAndQuotesStripped(): void
    {
        self::assertSame('Courier', SvgFontResolver::resolve('"Foo Bar", Consolas, sans-serif', false, false)->pdfName());
    }

    public function testUnknownFamilyFallsBackToHelvetica(): void
    {
        self::assertSame('Helvetica-Bold', SvgFontResolver::resolve('Wingdings', true, false)->pdfName());
    }
}
