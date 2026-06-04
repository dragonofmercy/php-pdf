<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit;

use DragonOfMercy\PhpPdf\ColumnFill;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Page\ColumnLayout;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;

final class DocumentColumnsTest extends TestCase
{
    public function testColumnStateDefaultsInactive(): void
    {
        $doc = new Document(Unit::PT);
        self::assertNull($doc->columnLayout());
        self::assertSame(0, $doc->columnIndex());
    }

    public function testAddPageResetsColumnIndexWhenLayoutActive(): void
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $layout = ColumnLayout::compute(2, 10.0, 0.0, 0.0, 200.0, ColumnFill::SEQUENTIAL);
        $doc->beginColumns($layout);
        $doc->setColumnIndex(1);
        $doc->addPage();
        self::assertSame(0, $doc->columnIndex(), 'a new page restarts at column 0 during column flow');
        $doc->endColumns();
        self::assertNull($doc->columnLayout());
    }
}
