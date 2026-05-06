<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit;

use PhpPdf\VerticalAlign;
use PHPUnit\Framework\TestCase;

final class VerticalAlignTest extends TestCase
{
    public function testCasesExist(): void
    {
        $cases = VerticalAlign::cases();
        self::assertCount(3, $cases);
        self::assertContains(VerticalAlign::TOP, $cases);
        self::assertContains(VerticalAlign::MIDDLE, $cases);
        self::assertContains(VerticalAlign::BOTTOM, $cases);
    }
}
