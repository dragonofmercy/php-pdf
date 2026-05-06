<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Page\ContentStream;
use DragonOfMercy\PhpPdf\PathOperation;
use PHPUnit\Framework\TestCase;

final class PathOperationTest extends TestCase
{
    public function testStrokeAppendsSOperator(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        $cs->append("x\n");
        (new PathOperation($cs))->stroke();
        self::assertStringEndsWith("x\nS\n", $cs->bytes());
    }

    public function testFillAppendsFOperator(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        $cs->append("x\n");
        (new PathOperation($cs))->fill();
        self::assertStringEndsWith("x\nf\n", $cs->bytes());
    }

    public function testStrokeAndFillAppendsBOperator(): void
    {
        $cs = new ContentStream(pageHeight: 100);
        $cs->append("x\n");
        (new PathOperation($cs))->strokeAndFill();
        self::assertStringEndsWith("x\nB\n", $cs->bytes());
    }
}
