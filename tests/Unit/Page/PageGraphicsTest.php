<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Page;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Page\ContentStream;
use DragonOfMercy\PhpPdf\Page\PageGraphics;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class PageGraphicsTest extends TestCase
{
    public function testRectEmitsRectangleOperator(): void
    {
        $stream = new ContentStream(841.89);
        (new PageGraphics($stream, Unit::PT))->rect(10, 20, 30, 40);
        self::assertStringContainsString(' re', $stream->bytes());
    }

    public function testSetStrokeColorEmitsStrokeOperator(): void
    {
        $stream = new ContentStream(841.89);
        (new PageGraphics($stream, Unit::PT))->setStrokeColor(Color::rgb(255, 0, 0));
        self::assertStringContainsString('RG', $stream->bytes());
    }

    public function testSaveRestoreEmitsQ(): void
    {
        $stream = new ContentStream(841.89);
        $g = new PageGraphics($stream, Unit::PT);
        $g->save();
        $g->restore();
        $bytes = $stream->bytes();
        self::assertStringContainsString('q', $bytes);
        self::assertStringContainsString('Q', $bytes);
    }
}
