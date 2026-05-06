<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Fit;
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
