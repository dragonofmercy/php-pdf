<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown;

use DragonOfMercy\PhpPdf\Markdown\InlineParser;
use DragonOfMercy\PhpPdf\Markdown\Node\{TextRun, LinkSpan, ImageSpan};
use PHPUnit\Framework\TestCase;

final class InlineParserTest extends TestCase
{
    public function testPlainText(): void
    {
        $n = InlineParser::parse('hello world');
        self::assertCount(1, $n);
        $run = $n[0];
        self::assertInstanceOf(TextRun::class, $run);
        self::assertSame('hello world', $run->text);
        self::assertFalse($run->bold);
    }

    public function testBoldItalicCode(): void
    {
        $n = InlineParser::parse('a **b** c *d* `e`');
        $plain = $n[0];
        $bold = $n[1];
        $italic = $n[3];
        $code = $n[5];
        self::assertInstanceOf(TextRun::class, $plain);
        self::assertInstanceOf(TextRun::class, $bold);
        self::assertInstanceOf(TextRun::class, $italic);
        self::assertInstanceOf(TextRun::class, $code);
        self::assertSame('a ', $plain->text);
        self::assertTrue($bold->bold);
        self::assertSame('b', $bold->text);
        self::assertTrue($italic->italic);
        self::assertSame('d', $italic->text);
        self::assertTrue($code->code);
        self::assertSame('e', $code->text);
    }

    public function testBoldItalicCombined(): void
    {
        $n = InlineParser::parse('***x***');
        self::assertCount(1, $n);
        $run = $n[0];
        self::assertInstanceOf(TextRun::class, $run);
        self::assertTrue($run->bold);
        self::assertTrue($run->italic);
        self::assertSame('x', $run->text);
    }

    public function testLink(): void
    {
        $n = InlineParser::parse('see [the site](https://x.test) now');
        $link = $n[1];
        self::assertInstanceOf(LinkSpan::class, $link);
        self::assertSame('https://x.test', $link->url);
        $label = $link->children[0];
        self::assertInstanceOf(TextRun::class, $label);
        self::assertSame('the site', $label->text);
    }

    public function testImage(): void
    {
        $n = InlineParser::parse('![logo](logo.png)');
        $image = $n[0];
        self::assertInstanceOf(ImageSpan::class, $image);
        self::assertSame('logo', $image->alt);
        self::assertSame('logo.png', $image->src);
    }

    public function testImageWrappedInLink(): void
    {
        $n = InlineParser::parse('[![alt](img)](url)');
        self::assertCount(1, $n);
        $link = $n[0];
        self::assertInstanceOf(LinkSpan::class, $link);
        self::assertSame('url', $link->url);
        self::assertCount(1, $link->children);
        $image = $link->children[0];
        self::assertInstanceOf(ImageSpan::class, $image);
        self::assertSame('alt', $image->alt);
        self::assertSame('img', $image->src);
    }

    public function testLinkWithBracketedLabel(): void
    {
        $n = InlineParser::parse('[a [b] c](url)');
        self::assertCount(1, $n);
        $link = $n[0];
        self::assertInstanceOf(LinkSpan::class, $link);
        self::assertSame('url', $link->url);
        $label = $link->children[0];
        self::assertInstanceOf(TextRun::class, $label);
        self::assertSame('a [b] c', $label->text);
    }

    public function testBackslashEscapeKeepsLiteralAsterisk(): void
    {
        $n = InlineParser::parse('a \\* b');
        self::assertCount(1, $n);
        $run = $n[0];
        self::assertInstanceOf(TextRun::class, $run);
        self::assertSame('a * b', $run->text);
    }

    public function testCodeSpanIsLiteral(): void
    {
        $n = InlineParser::parse('`**not bold**`');
        self::assertCount(1, $n);
        $run = $n[0];
        self::assertInstanceOf(TextRun::class, $run);
        self::assertTrue($run->code);
        self::assertSame('**not bold**', $run->text);
    }
}
