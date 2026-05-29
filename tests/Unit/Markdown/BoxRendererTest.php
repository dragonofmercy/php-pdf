<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown;

use DragonOfMercy\PhpPdf\Markdown\{MarkdownParser, MarkdownStyle, BoxRenderer, BreakMode};
use DragonOfMercy\PhpPdf\{Document, Unit, Font};
use PHPUnit\Framework\TestCase;

final class BoxRendererTest extends TestCase
{
    public function testRendersHeadingAndParagraphReturningHeight(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $ast = MarkdownParser::parse("# Title\n\nBody **bold** text.");
        $height = (new BoxRenderer())->render($ast, MarkdownStyle::default(), 20.0, 20.0, 200.0, $page, BreakMode::ATOMIC);
        self::assertGreaterThan(0.0, $height);
        $stream = $page->contentStream()->bytes();
        self::assertStringContainsString('Tf', $stream); // a font was set / text emitted
    }

    public function testDeterministicAcrossTwoRenders(): void
    {
        $build = static function (): string {
            $doc = new Document(Unit::PT);
            $page = $doc->addPage();
            $page->setFont(Font::helvetica(), 11.0);
            $ast = MarkdownParser::parse("# H\n\n- one\n- two\n\n> quote\n\n```\ncode\n```");
            (new BoxRenderer())->render($ast, MarkdownStyle::default(), 20.0, 20.0, 200.0, $page, BreakMode::ATOMIC);
            return $page->contentStream()->bytes();
        };
        self::assertSame($build(), $build());
    }
}
