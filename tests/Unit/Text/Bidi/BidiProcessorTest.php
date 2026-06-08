<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Text\Bidi;

use DragonOfMercy\PhpPdf\Text\Bidi\BidiProcessor;
use DragonOfMercy\PhpPdf\Text\Direction;
use PHPUnit\Framework\TestCase;

final class BidiProcessorTest extends TestCase
{
    public function testResolveAutoLatinIsLtr(): void
    {
        self::assertSame(Direction::LTR, BidiProcessor::resolveBaseDirection('hello', Direction::AUTO));
    }

    public function testResolveAutoHebrewIsRtl(): void
    {
        self::assertSame(Direction::RTL, BidiProcessor::resolveBaseDirection("\u{05D0}\u{05D1}", Direction::AUTO));
    }

    public function testResolveExplicitPassesThrough(): void
    {
        self::assertSame(Direction::RTL, BidiProcessor::resolveBaseDirection('hello', Direction::RTL));
        self::assertSame(Direction::LTR, BidiProcessor::resolveBaseDirection("\u{05D0}", Direction::LTR));
    }

    public function testReorderLtrLatinIsByteIdentical(): void
    {
        $line = 'Hello, World! 123';
        self::assertSame($line, BidiProcessor::reorder($line, Direction::LTR));
    }

    public function testReorderEmptyString(): void
    {
        self::assertSame('', BidiProcessor::reorder('', Direction::RTL));
    }

    public function testReorderHebrewRtlReverses(): void
    {
        self::assertSame(
            "\u{05D2}\u{05D1}\u{05D0}",
            BidiProcessor::reorder("\u{05D0}\u{05D1}\u{05D2}", Direction::RTL),
        );
    }
}
