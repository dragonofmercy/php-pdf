<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Text\Direction;
use PHPUnit\Framework\TestCase;

final class DocumentBaseDirectionTest extends TestCase
{
    public function testDefaultIsLtr(): void
    {
        self::assertSame(Direction::LTR, (new Document())->baseDirection());
    }

    public function testSetterIsFluentAndStores(): void
    {
        $doc = new Document();
        self::assertSame($doc, $doc->setBaseDirection(Direction::RTL));
        self::assertSame(Direction::RTL, $doc->baseDirection());
    }
}
