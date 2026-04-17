<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit;

use PhpPdf\LineCap;
use PHPUnit\Framework\TestCase;

final class LineCapTest extends TestCase
{
    public function testPdfIntegerValuesMatchSpec(): void
    {
        self::assertSame(0, LineCap::BUTT->value);
        self::assertSame(1, LineCap::ROUND->value);
        self::assertSame(2, LineCap::SQUARE->value);
    }
}
