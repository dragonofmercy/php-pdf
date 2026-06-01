<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Svg;

use DragonOfMercy\PhpPdf\Svg\Parser;
use DragonOfMercy\PhpPdf\Svg\SvgGroup;
use DragonOfMercy\PhpPdf\Svg\SvgNode;
use DragonOfMercy\PhpPdf\Svg\SvgTextPath;
use PHPUnit\Framework\TestCase;

final class TextPathParseTest extends TestCase
{
    private function findTextPath(SvgNode $node): ?SvgTextPath
    {
        if ($node instanceof SvgTextPath) {
            return $node;
        }
        if ($node instanceof SvgGroup) {
            foreach ($node->children as $child) {
                $found = $this->findTextPath($child);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function firstTextPath(string $svg): ?SvgTextPath
    {
        $meta = Parser::parse($svg);
        foreach ($meta->root->children as $child) {
            $tp = $this->findTextPath($child);
            if ($tp !== null) {
                return $tp;
            }
        }
        return null;
    }

    public function testTextPathReferencingAPathProducesANode(): void
    {
        $svg = <<<XML
        <svg xmlns="http://www.w3.org/2000/svg" width="300" height="120" viewBox="0 0 300 120">
          <defs><path id="curve" d="M20,80 L280,80"/></defs>
          <text font-size="18"><textPath href="#curve" startOffset="10">Hello</textPath></text>
        </svg>
        XML;
        $tp = $this->firstTextPath($svg);
        self::assertNotNull($tp);
        self::assertSame(10.0, $tp->startOffset);
        self::assertFalse($tp->startOffsetIsPercent);
        self::assertNotSame([], $tp->pathCommands);
        $text = '';
        foreach ($tp->spans as $s) {
            $text .= $s->text;
        }
        self::assertStringContainsString('Hello', $text);
    }

    public function testStartOffsetPercent(): void
    {
        $svg = <<<XML
        <svg xmlns="http://www.w3.org/2000/svg" width="300" height="120" viewBox="0 0 300 120">
          <path id="c" d="M0,50 L300,50"/>
          <text><textPath href="#c" startOffset="25%">x</textPath></text>
        </svg>
        XML;
        $tp = $this->firstTextPath($svg);
        self::assertNotNull($tp);
        self::assertSame(25.0, $tp->startOffset);
        self::assertTrue($tp->startOffsetIsPercent);
    }

    public function testUnresolvedHrefProducesNoTextNode(): void
    {
        $svg = <<<XML
        <svg xmlns="http://www.w3.org/2000/svg" width="300" height="120" viewBox="0 0 300 120">
          <text><textPath href="#missing">x</textPath></text>
        </svg>
        XML;
        self::assertNull($this->firstTextPath($svg));
    }
}
