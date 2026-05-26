<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form;

use DragonOfMercy\PhpPdf\Form\FieldBorderStyle;
use PHPUnit\Framework\TestCase;

final class FieldBorderStyleTest extends TestCase
{
    public function testCasesExist(): void
    {
        $names = array_map(static fn(FieldBorderStyle $c): string => $c->name, FieldBorderStyle::cases());
        self::assertSame(['SOLID', 'DASHED', 'BEVELED', 'INSET', 'UNDERLINE'], $names);
    }
}
