<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit;

use PhpPdf\LineJoin;
use PHPUnit\Framework\TestCase;

final class LineJoinTest extends TestCase
{
    public function testPdfIntegerValuesMatchSpec(): void
    {
        self::assertSame(0, LineJoin::MITER->value);
        self::assertSame(1, LineJoin::ROUND->value);
        self::assertSame(2, LineJoin::BEVEL->value);
    }
}
