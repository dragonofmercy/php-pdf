<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown;

use DragonOfMercy\PhpPdf\Markdown\MarkdownParser;
use DragonOfMercy\PhpPdf\Markdown\Node\BlockQuote;
use DragonOfMercy\PhpPdf\Markdown\Node\BulletList;
use DragonOfMercy\PhpPdf\Markdown\Node\CodeBlock;
use DragonOfMercy\PhpPdf\Markdown\Node\Heading;
use DragonOfMercy\PhpPdf\Markdown\Node\OrderedList;
use DragonOfMercy\PhpPdf\Markdown\Node\Paragraph;
use DragonOfMercy\PhpPdf\Markdown\Node\ThematicBreak;
use PHPUnit\Framework\TestCase;

final class MarkdownParserTest extends TestCase
{
    public function testHeadingAndParagraph(): void
    {
        $blocks = MarkdownParser::parse("# Title\n\nA paragraph.");
        self::assertCount(2, $blocks);

        $heading = $blocks[0];
        self::assertInstanceOf(Heading::class, $heading);
        self::assertSame(1, $heading->level);

        $paragraph = $blocks[1];
        self::assertInstanceOf(Paragraph::class, $paragraph);
    }

    public function testMultiLineParagraphJoins(): void
    {
        $blocks = MarkdownParser::parse("line one\nline two");
        self::assertCount(1, $blocks);

        $paragraph = $blocks[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
    }

    public function testBulletList(): void
    {
        $blocks = MarkdownParser::parse("- a\n- b\n- c");
        self::assertCount(1, $blocks);

        $list = $blocks[0];
        self::assertInstanceOf(BulletList::class, $list);
        self::assertCount(3, $list->items);
    }

    public function testOrderedListStartAtThree(): void
    {
        $blocks = MarkdownParser::parse("3. a\n4. b");
        self::assertCount(1, $blocks);

        $list = $blocks[0];
        self::assertInstanceOf(OrderedList::class, $list);
        self::assertSame(3, $list->start);
        self::assertCount(2, $list->items);
    }

    public function testFencedCodeBlock(): void
    {
        $blocks = MarkdownParser::parse("```php\necho 1;\n```");
        self::assertCount(1, $blocks);

        $code = $blocks[0];
        self::assertInstanceOf(CodeBlock::class, $code);
        self::assertSame('php', $code->lang);
        self::assertSame("echo 1;\n", $code->text);
    }

    public function testBlockQuote(): void
    {
        $blocks = MarkdownParser::parse("> quoted\n> more");
        self::assertCount(1, $blocks);

        $quote = $blocks[0];
        self::assertInstanceOf(BlockQuote::class, $quote);

        $inner = $quote->blocks[0];
        self::assertInstanceOf(Paragraph::class, $inner);
    }

    public function testThematicBreak(): void
    {
        $blocks = MarkdownParser::parse("a\n\n---\n\nb");
        self::assertCount(3, $blocks);

        $first = $blocks[0];
        $rule = $blocks[1];
        $last = $blocks[2];
        self::assertInstanceOf(Paragraph::class, $first);
        self::assertInstanceOf(ThematicBreak::class, $rule);
        self::assertInstanceOf(Paragraph::class, $last);
    }

    public function testNestedBulletList(): void
    {
        $blocks = MarkdownParser::parse("- a\n    - a1\n- b");
        self::assertCount(1, $blocks);

        $list = $blocks[0];
        self::assertInstanceOf(BulletList::class, $list);
        self::assertCount(2, $list->items);

        $firstItem = $list->items[0];
        $nested = $firstItem->blocks[1];
        self::assertInstanceOf(BulletList::class, $nested);
        self::assertCount(1, $nested->items);
    }
}
