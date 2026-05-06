<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Writer\Object;

use DragonOfMercy\PhpPdf\Writer\Object\PdfBoolean;
use PHPUnit\Framework\TestCase;

final class PdfBooleanTest extends TestCase
{
    public function testTrueEmitsKeyword(): void
    {
        self::assertSame('true', PdfBoolean::true()->toBytes());
    }

    public function testFalseEmitsKeyword(): void
    {
        self::assertSame('false', PdfBoolean::false()->toBytes());
    }

    public function testFromBool(): void
    {
        self::assertSame('true', PdfBoolean::of(true)->toBytes());
        self::assertSame('false', PdfBoolean::of(false)->toBytes());
    }
}
