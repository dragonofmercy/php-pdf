<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\Page\Operators;
use PHPUnit\Framework\TestCase;

final class OperatorsTest extends TestCase
{
    public function testShowTextArrayWrapsBodyInTjOperator(): void
    {
        self::assertSame("[(foo )-50(bar)] TJ\n", Operators::showTextArray('(foo )-50(bar)'));
    }
}
