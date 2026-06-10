<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer\Object;

use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use PHPUnit\Framework\TestCase;

final class PdfNullTest extends TestCase
{
    public function testNullSerializesAsKeyword(): void
    {
        self::assertSame('null', PdfNull::instance()->toBytes());
    }

    public function testInstanceIsShared(): void
    {
        self::assertSame(PdfNull::instance(), PdfNull::instance());
    }
}
