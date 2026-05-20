<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Outline;

use DragonOfMercy\PhpPdf\Outline\DestinationFit;
use PHPUnit\Framework\TestCase;

final class DestinationFitTest extends TestCase
{
    public function testThreeCasesAreDeclared(): void
    {
        $names = array_map(static fn (DestinationFit $c): string => $c->name, DestinationFit::cases());
        self::assertSame(['Xyz', 'Fit', 'FitH'], $names);
    }
}
