<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Page\ContentStream;
use DragonOfMercy\PhpPdf\Path;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    public function testMoveToAppendsNothingUntilTerminal(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        $path = new Path($cs);
        $path->moveTo(10, 20);
        self::assertTrue($cs->isEmpty());
    }

    public function testStrokeFlushesBufferedOpsWithS(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        (new Path($cs))->moveTo(10, 20)->lineTo(30, 40)->stroke();
        $body = substr($cs->bytes(), strlen("1 0 0 -1 0 100 cm\n"));
        self::assertSame("10 20 m\n30 40 l\nS\n", $body);
    }

    public function testFillFlushesBufferedOpsWithF(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        (new Path($cs))->moveTo(0, 0)->lineTo(10, 0)->lineTo(10, 10)->close()->fill();
        $body = substr($cs->bytes(), strlen("1 0 0 -1 0 100 cm\n"));
        self::assertSame("0 0 m\n10 0 l\n10 10 l\nh\nf\n", $body);
    }

    public function testStrokeAndFillFlushesWithB(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        (new Path($cs))->moveTo(0, 0)->lineTo(5, 5)->strokeAndFill();
        $body = substr($cs->bytes(), strlen("1 0 0 -1 0 100 cm\n"));
        self::assertSame("0 0 m\n5 5 l\nB\n", $body);
    }

    public function testCurveTo(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        (new Path($cs))->moveTo(0, 0)->curveTo(1, 2, 3, 4, 5, 6)->stroke();
        $body = substr($cs->bytes(), strlen("1 0 0 -1 0 100 cm\n"));
        self::assertSame("0 0 m\n1 2 3 4 5 6 c\nS\n", $body);
    }

    public function testChainingReturnsSelf(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        $path = new Path($cs);
        self::assertSame($path, $path->moveTo(0, 0));
        self::assertSame($path, $path->lineTo(1, 1));
        self::assertSame($path, $path->close());
    }
}
