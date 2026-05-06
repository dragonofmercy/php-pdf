<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit;

use PhpPdf\Fit;
use PHPUnit\Framework\TestCase;

final class FitTest extends TestCase
{
    public function testCasesExist(): void
    {
        $cases = Fit::cases();
        self::assertCount(3, $cases);
        self::assertContains(Fit::NONE, $cases);
        self::assertContains(Fit::CONDENSE, $cases);
        self::assertContains(Fit::SHRINK, $cases);
    }
}
