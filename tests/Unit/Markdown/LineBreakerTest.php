<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Markdown;

use DragonOfMercy\PhpPdf\Markdown\{LineBreaker, StyledRun};
use DragonOfMercy\PhpPdf\{Color, Font};
use PHPUnit\Framework\TestCase;

final class LineBreakerTest extends TestCase
{
    /** monospace: width = strlen * sizePt * 0.1 */
    private function measurer(): callable
    {
        return static fn (string $t, Font $f, float $sizePt): float => strlen($t) * $sizePt * 0.1;
    }

    public function testSingleRunFitsOneLine(): void
    {
        $runs = [new StyledRun('hello', Font::helvetica(), Color::rgb(0,0,0), 10.0, false, null)];
        $lines = (new LineBreaker($this->measurer()))->layout($runs, 100.0);
        self::assertCount(1, $lines);
        self::assertSame('hello', $lines[0]->segments[0]->run->text);
    }

    public function testWrapsAtWordBoundary(): void
    {
        // each "aaaaa" = 5 chars * 10 * 0.1 = 5pt; width 12pt -> 2 words per line
        $runs = [new StyledRun('aaaaa aaaaa aaaaa', Font::helvetica(), Color::rgb(0,0,0), 10.0, false, null)];
        $lines = (new LineBreaker($this->measurer()))->layout($runs, 12.0);
        self::assertCount(2, $lines);
    }

    public function testMixedFontsStayOnSameLine(): void
    {
        $runs = [
            new StyledRun('bold ', Font::helvetica()->bold(), Color::rgb(0,0,0), 10.0, false, null),
            new StyledRun('plain', Font::helvetica(), Color::rgb(0,0,0), 10.0, false, null),
        ];
        $lines = (new LineBreaker($this->measurer()))->layout($runs, 100.0);
        self::assertCount(1, $lines);
        self::assertCount(2, $lines[0]->segments);
        self::assertTrue($lines[0]->segments[0]->run->font->isBold());
    }

    public function testLongWordOverflowsAloneOnItsLine(): void
    {
        // "aaaaaaaaaa" = 10 chars * 10 * 0.1 = 10pt but width is 4pt -> still one segment, alone
        $runs = [new StyledRun('aaaaaaaaaa', Font::helvetica(), Color::rgb(0,0,0), 10.0, false, null)];
        $lines = (new LineBreaker($this->measurer()))->layout($runs, 4.0);
        self::assertCount(1, $lines);
        self::assertSame('aaaaaaaaaa', $lines[0]->segments[0]->run->text);
    }
}
