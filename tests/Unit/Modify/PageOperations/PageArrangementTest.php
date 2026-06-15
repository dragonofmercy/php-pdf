<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Modify\PageOperations\PageArrangement;
use PHPUnit\Framework\TestCase;

final class PageArrangementTest extends TestCase
{
    /** original page object numbers: page1=10, page2=11, page3=12 */
    private const array OBJNUMS = [10, 11, 12];

    public function testNoOpKeepsOrder(): void
    {
        $a = new PageArrangement(self::OBJNUMS, [], null);
        self::assertSame([10, 11, 12], $a->finalOrder());
        self::assertSame([], $a->deletedObjectNumbers());
    }

    public function testDeleteMiddle(): void
    {
        $a = new PageArrangement(self::OBJNUMS, [2], null);
        self::assertSame([10, 12], $a->finalOrder());
        self::assertSame([11], $a->deletedObjectNumbers());
    }

    public function testReorderOnly(): void
    {
        $a = new PageArrangement(self::OBJNUMS, [], [3, 1, 2]);
        self::assertSame([12, 10, 11], $a->finalOrder());
        self::assertSame([], $a->deletedObjectNumbers());
    }

    public function testDeleteThenReorderSurvivors(): void
    {
        $a = new PageArrangement(self::OBJNUMS, [2], [3, 1]);
        self::assertSame([12, 10], $a->finalOrder());
        self::assertSame([11], $a->deletedObjectNumbers());
    }

    public function testDeleteOutOfRangeThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Cannot delete page 4: document has 3 pages');
        new PageArrangement(self::OBJNUMS, [4], null);
    }

    public function testDeleteAllThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Cannot delete every page');
        new PageArrangement(self::OBJNUMS, [1, 2, 3], null);
    }

    public function testReorderNotAPermutationThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('reorderPages must list every surviving page exactly once');
        new PageArrangement(self::OBJNUMS, [], [1, 2]);
    }

    public function testReorderReferencesDeletedPageThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('reorderPages references page 2 which was deleted');
        new PageArrangement(self::OBJNUMS, [2], [1, 2, 3]);
    }

    public function testReorderOutOfRangeThrows(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('reorderPages references page 5 which does not exist');
        new PageArrangement(self::OBJNUMS, [], [1, 2, 5]);
    }
}
