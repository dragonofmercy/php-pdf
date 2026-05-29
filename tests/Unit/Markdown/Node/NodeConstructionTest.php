<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown\Node;

use DragonOfMercy\PhpPdf\Markdown\Node\{Heading, Paragraph, TextRun, BulletList, OrderedList, ListItem, CodeBlock, BlockQuote, ThematicBreak, LinkSpan, ImageSpan, BlockNode, InlineNode};
use DragonOfMercy\PhpPdf\Exception\PdfException;
use PHPUnit\Framework\TestCase;

final class NodeConstructionTest extends TestCase
{
    public function testTextRunHoldsFlags(): void
    {
        $r = new TextRun('hi', bold: true, italic: false, code: false);
        self::assertSame('hi', $r->text);
        self::assertTrue($r->bold);
        self::assertInstanceOf(InlineNode::class, $r);
    }

    public function testHeadingLevelRange(): void
    {
        $h = new Heading(2, [new TextRun('Title', false, false, false)]);
        self::assertSame(2, $h->level);
        self::assertInstanceOf(BlockNode::class, $h);
    }

    public function testHeadingRejectsBadLevel(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Heading level must be 1-6, got 7');
        new Heading(7, []);
    }

    public function testOrderedListStart(): void
    {
        $li = new ListItem([new Paragraph([new TextRun('x', false, false, false)])]);
        $ol = new OrderedList(3, [$li], tight: true);
        self::assertSame(3, $ol->start);
        self::assertCount(1, $ol->items);
    }

    public function testCodeBlockKeepsLang(): void
    {
        $c = new CodeBlock("echo 1;\n", 'php');
        self::assertSame('php', $c->lang);
    }
}
