<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgColor;
use DragonOfMercy\PhpPdf\Svg\SvgGradient;
use DragonOfMercy\PhpPdf\Svg\SvgPath;
use DragonOfMercy\PhpPdf\Svg\SvgRect;
use PHPUnit\Framework\TestCase;

final class GradientParseIntegrationTest extends TestCase
{
    public function testRectFillResolvesToGradient(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient></defs>'
            . '<rect width="10" height="10" fill="url(#g)"/></svg>';
        $meta = Parser::parse($svg);
        $rect = $meta->root->children[0];
        self::assertInstanceOf(SvgRect::class, $rect);
        self::assertInstanceOf(SvgGradient::class, $rect->paint()->fill);
    }

    public function testUnknownUrlFallsBackToBlack(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<rect width="10" height="10" fill="url(#missing)"/></svg>';
        $meta = Parser::parse($svg);
        $rect = $meta->root->children[0];
        self::assertInstanceOf(SvgRect::class, $rect);
        self::assertNotInstanceOf(SvgGradient::class, $rect->paint()->fill);
        self::assertNotNull($rect->paint()->fill);
    }

    public function testUrlWithFallbackColorUsesFallbackWhenMissing(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<path d="M0 0 L10 10" fill="url(#missing) #00ff00"/></svg>';
        $meta = Parser::parse($svg);
        $path = $meta->root->children[0];
        self::assertInstanceOf(SvgPath::class, $path);
        $fill = $path->paint()->fill;
        self::assertInstanceOf(SvgColor::class, $fill);
        self::assertEqualsWithDelta(0.0, $fill->r, 1e-9);
        self::assertEqualsWithDelta(1.0, $fill->g, 1e-9);
        self::assertEqualsWithDelta(0.0, $fill->b, 1e-9);
    }

    public function testStrokeGradientResolves(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<defs><linearGradient id="g"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient></defs>'
            . '<rect width="10" height="10" fill="none" stroke="url(#g)"/></svg>';
        $meta = Parser::parse($svg);
        $rect = $meta->root->children[0];
        self::assertInstanceOf(SvgRect::class, $rect);
        self::assertInstanceOf(SvgGradient::class, $rect->paint()->stroke);
    }
}
