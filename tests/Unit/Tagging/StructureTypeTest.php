<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Tagging;

use DragonOfMercy\PhpPdf\Tagging\StructureType;
use PHPUnit\Framework\TestCase;

final class StructureTypeTest extends TestCase
{
    public function testBackingValueIsThePdfTagName(): void
    {
        self::assertSame('Document', StructureType::Document->value);
        self::assertSame('H1', StructureType::H1->value);
        self::assertSame('TD', StructureType::TD->value);
        self::assertSame('Figure', StructureType::Figure->value);
    }

    public function testHeadingForLevelClampsToH6(): void
    {
        self::assertSame(StructureType::H1, StructureType::headingForLevel(1));
        self::assertSame(StructureType::H6, StructureType::headingForLevel(6));
        self::assertSame(StructureType::H6, StructureType::headingForLevel(9));
    }

    public function testHeadingForLevelRejectsBelowOne(): void
    {
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        StructureType::headingForLevel(0);
    }
}
