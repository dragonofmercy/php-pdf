<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Document;

use DragonOfMercy\PhpPdf\Document;
use PHPUnit\Framework\TestCase;

final class LinkStructParentIndexTest extends TestCase
{
    public function testReturnsAscendingOrdinals(): void
    {
        $doc = new Document();

        self::assertSame(0, $doc->nextLinkStructParentIndex());
        self::assertSame(1, $doc->nextLinkStructParentIndex());
        self::assertSame(2, $doc->nextLinkStructParentIndex());
    }
}
