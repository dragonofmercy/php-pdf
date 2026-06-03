<?php
declare(strict_types=1);
namespace DragonOfMercy\PhpPdf\Tests\Unit\Table;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\Table\Cell;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Table\TableResult;
use DragonOfMercy\PhpPdf\Table\TableStyle;
use PHPUnit\Framework\TestCase;

final class PageTableTest extends TestCase
{
    public function testSimpleTableReturnsResult(): void
    {
        $doc = new Document(); // mm
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);

        $result = $page->table(
            columns: [
                Column::of('name', 'Nom')->fill(),
                Column::of('price', 'Prix')->width(30.0)->align(TextAlign::RIGHT),
            ],
            rows: [
                ['name' => 'Cafe', 'price' => '2.50'],
                ['name' => 'Croissant', 'price' => '1.20'],
            ],
            x: 20.0, y: 30.0, width: 170.0,
        );

        self::assertInstanceOf(TableResult::class, $result);
        self::assertSame(2, $result->rowCount);
        self::assertSame(1, $result->pageCount);
        self::assertGreaterThan(30.0, $result->y); // advanced below the start
    }

    public function testCursorAdvanceWithLnBelow(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->setXY(20.0, 30.0);

        $page->table(
            columns: [Column::of('k', 'K')->fill()],
            rows: [['k' => 'a'], ['k' => 'b']],
            ln: NextPosition::BELOW,
        );
        // cursor Y should now sit below the table
        self::assertGreaterThan(30.0, $page->getY());
    }

    public function testLongTablePaginates(): void
    {
        $doc = new Document();
        $doc->setAutoPageBreak(true);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);

        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = ['n' => (string) $i, 'label' => 'Row number ' . $i];
        }

        $result = $page->table(
            columns: [Column::of('n', '#')->width(20.0), Column::of('label', 'Label')->fill()],
            rows: $rows,
            x: 20.0, y: 20.0, width: 170.0,
            style: TableStyle::default()->withRepeatHeader(true),
        );

        self::assertGreaterThan(1, $result->pageCount);
        self::assertSame(120, $result->rowCount);
    }

    public function testImageCellDoesNotThrow(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);

        $result = $page->table(
            columns: [Column::of('avatar', '')->width(14.0), Column::of('name', 'Nom')->fill()],
            rows: [
                [
                    'avatar' => Cell::image(__DIR__ . '/../../Golden/assets/png-alpha-rgba-16x16.png', w: 10.0, h: 10.0),
                    'name' => 'Alice',
                ],
            ],
            x: 20.0, y: 30.0, width: 170.0,
        );
        self::assertSame(1, $result->rowCount);
        self::assertStringContainsString('%PDF-', $doc->output());
    }
}
