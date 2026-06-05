<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Tagging\StructElem;
use DragonOfMercy\PhpPdf\Tagging\StructureType;
use DragonOfMercy\PhpPdf\Tagging\TableScope;
use PHPUnit\Framework\TestCase;

final class StructElemAttributesTest extends TestCase
{
    public function testAltDefaultsNullAndIsSettable(): void
    {
        $elem = new StructElem(StructureType::Figure);
        self::assertNull($elem->alt());
        $elem->setAlt('A revenue chart');
        self::assertSame('A revenue chart', $elem->alt());
    }

    public function testScopeDefaultsNullAndIsSettable(): void
    {
        $elem = new StructElem(StructureType::TH);
        self::assertNull($elem->scope());
        $elem->setScope(TableScope::Column);
        self::assertSame(TableScope::Column, $elem->scope());
    }
}
