<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Table\Cell;
use DragonOfMercy\PhpPdf\Table\CellStyle;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Table\ColumnGroup;
use DragonOfMercy\PhpPdf\Table\TableBorders;
use DragonOfMercy\PhpPdf\Table\TableStyle;
use DragonOfMercy\PhpPdf\TextAlign;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class TableGoldenTest extends TestCase
{
    // --- Task 10: basic table ---

    public static function buildBasic(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->table(
            columns: [
                Column::of('name', 'Article')->fill(),
                Column::of('qty', 'Qte')->width(20.0)->align(TextAlign::RIGHT),
                Column::of('price', 'Prix')->width(30.0)->align(TextAlign::RIGHT),
            ],
            rows: [
                ['name' => 'Cafe', 'qty' => '2', 'price' => '5.00'],
                ['name' => 'Croissant', 'qty' => '3', 'price' => '3.60'],
                ['name' => 'Jus orange', 'qty' => '1', 'price' => '2.80'],
            ],
            x: 20.0, y: 30.0, width: 170.0,
            style: TableStyle::default()
                ->withBorder(TableBorders::GRID)
                ->withHeader(fill: Color::gray(238), bold: true),
        );
        return $doc->output();
    }

    public function testBasicMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/table/table-basic.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildBasic(), 'table-basic.pdf diverges; regenerate if intended.');
    }

    public function testBasicPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/table/table-basic.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    // --- Task 11: styled table (zebra + horizontal borders + conditional) ---

    public static function buildStyled(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->table(
            columns: [
                Column::of('item', 'Poste')->fill(),
                Column::of('amount', 'Montant')->width(35.0)->align(TextAlign::RIGHT),
            ],
            rows: [
                ['item' => 'Vente A', 'amount' => '120.00'],
                ['item' => 'Remboursement', 'amount' => '-15.00'],
                ['item' => 'Vente B', 'amount' => '80.00'],
                ['item' => 'Avoir', 'amount' => '-5.00'],
            ],
            x: 20.0, y: 30.0, width: 170.0,
            style: TableStyle::default()
                ->withBorder(TableBorders::HORIZONTAL)
                ->withHeader(fill: Color::gray(238), bold: true)
                ->withZebra(Color::rgb(255, 255, 255), Color::gray(247))
                ->withCellStyle(static function (mixed $value, array $row, Column $col): ?CellStyle {
                    if ($col->key === 'amount' && is_string($value) && str_starts_with($value, '-')) {
                        return CellStyle::new()->withTextColor(Color::rgb(192, 0, 0));
                    }
                    return null;
                }),
        );
        return $doc->output();
    }

    public function testStyledMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/table/table-styled.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildStyled(), 'table-styled.pdf diverges; regenerate if intended.');
    }

    public function testStyledPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/table/table-styled.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    // --- Task 12: paginated (with and without header repeat) ---

    public static function buildPaginated(bool $repeat): string
    {
        $doc = new Document();
        $doc->setAutoPageBreak(true);
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);

        $rows = [];
        for ($i = 1; $i <= 90; $i++) {
            $rows[] = ['n' => (string) $i, 'label' => 'Ligne numero ' . $i];
        }

        $page->table(
            columns: [Column::of('n', '#')->width(20.0)->align(TextAlign::RIGHT), Column::of('label', 'Libelle')->fill()],
            rows: $rows,
            x: 20.0, y: 20.0, width: 170.0,
            style: TableStyle::default()->withHeader(fill: Color::gray(238), bold: true)->withRepeatHeader($repeat),
        );
        return $doc->output();
    }

    public function testPaginatedMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/table/table-paginated.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildPaginated(true), 'table-paginated.pdf diverges; regenerate if intended.');
    }

    public function testPaginatedNoHeaderMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/table/table-paginated-noheader.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildPaginated(false), 'table-paginated-noheader.pdf diverges; regenerate if intended.');
    }

    public function testPaginatedPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        foreach (['table-paginated.pdf', 'table-paginated-noheader.pdf'] as $f) {
            $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/table/' . $f]);
            $p->run();
            self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
        }
    }

    // --- Task 13: avatars (image cells) ---

    public static function buildAvatars(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);

        $avatar = __DIR__ . '/assets/png-alpha-rgba-16x16.png';
        $page->table(
            columns: [
                Column::of('avatar', '')->width(16.0),
                Column::of('name', 'Membre')->fill(),
                Column::of('role', 'Role')->width(40.0),
            ],
            rows: [
                ['avatar' => Cell::image($avatar, w: 10.0, h: 10.0), 'name' => 'Alice Martin', 'role' => 'Admin'],
                ['avatar' => Cell::image($avatar, w: 10.0, h: 10.0), 'name' => 'Bob Durand', 'role' => 'Editeur'],
                ['avatar' => Cell::image($avatar, w: 10.0, h: 10.0), 'name' => 'Carol Petit', 'role' => 'Lecteur'],
            ],
            x: 20.0, y: 30.0, width: 170.0,
            style: TableStyle::default()->withBorder(TableBorders::GRID)->withHeader(fill: Color::gray(238), bold: true),
        );
        return $doc->output();
    }

    public function testAvatarsMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/table/table-avatars.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildAvatars(), 'table-avatars.pdf diverges; regenerate if intended.');
    }

    public function testAvatarsPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/table/table-avatars.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    // --- Task 6: justified table column ---

    public static function buildJustified(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->table(
            columns: [
                Column::of('desc', 'Description')->fill()->align(TextAlign::JUSTIFY),
                Column::of('code', 'Code')->width(25.0),
            ],
            rows: [
                ['desc' => 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.', 'code' => 'A001'],
                ['desc' => 'Ut enim ad minim veniam quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat duis aute irure.', 'code' => 'B002'],
                ['desc' => 'Excepteur sint occaecat cupidatat non proident sunt in culpa qui officia deserunt mollit anim id est laborum perspiciatis.', 'code' => 'C003'],
            ],
            x: 20.0, y: 30.0, width: 170.0,
            style: TableStyle::default()
                ->withBorder(TableBorders::GRID)
                ->withHeader(fill: Color::gray(238), bold: true),
        );
        return $doc->output();
    }

    public function testJustifiedMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/table/table-justify.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildJustified(), 'table-justify.pdf diverges; regenerate if intended.');
    }

    public function testJustifiedPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/table/table-justify.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    // --- Cell spanning: colspan ---

    public static function buildColspan(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->table(
            columns: [
                Column::of('a', 'Q1')->width(40.0)->align(TextAlign::RIGHT),
                Column::of('b', 'Q2')->width(40.0)->align(TextAlign::RIGHT),
                Column::of('c', 'Q3')->width(40.0)->align(TextAlign::RIGHT),
            ],
            rows: [
                ['a' => '100', 'b' => '120', 'c' => '90'],
                ['a' => '110', 'b' => '130', 'c' => '95'],
                ['a' => Cell::of('Total year: 870')->colSpan(3)->bold()->align(TextAlign::CENTER)],
            ],
            x: 20.0, y: 30.0, width: 120.0,
            style: TableStyle::default()
                ->withBorder(TableBorders::GRID)
                ->withHeader(fill: Color::gray(238), bold: true),
        );
        return $doc->output();
    }

    public function testColspanMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/table/table-colspan.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildColspan(), 'table-colspan.pdf diverges; regenerate if intended.');
    }

    public function testColspanPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/table/table-colspan.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }

    // --- Cell spanning: grouped header ---

    public static function buildGroupedHeader(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->setFont(Font::helvetica(), 11.0);
        $page->table(
            columns: [
                Column::of('name', 'Name')->width(40.0),
                Column::of('jan', 'Jan')->width(20.0)->align(TextAlign::RIGHT),
                Column::of('feb', 'Feb')->width(20.0)->align(TextAlign::RIGHT),
                Column::of('mar', 'Mar')->width(20.0)->align(TextAlign::RIGHT),
                Column::of('apr', 'Apr')->width(20.0)->align(TextAlign::RIGHT),
                Column::of('may', 'May')->width(20.0)->align(TextAlign::RIGHT),
                Column::of('jun', 'Jun')->width(20.0)->align(TextAlign::RIGHT),
            ],
            rows: [
                ['name' => 'Alice', 'jan' => '1', 'feb' => '2', 'mar' => '3', 'apr' => '4', 'may' => '5', 'jun' => '6'],
                ['name' => 'Bob',   'jan' => '7', 'feb' => '8', 'mar' => '9', 'apr' => '1', 'may' => '2', 'jun' => '3'],
            ],
            x: 20.0, y: 30.0, width: 160.0,
            style: TableStyle::default()
                ->withBorder(TableBorders::GRID)
                ->withHeader(fill: Color::gray(238), bold: true)
                ->withColumnGroups(
                    ColumnGroup::spacer(),
                    ColumnGroup::of('Q1', 3)->fill(Color::gray(220)),
                    ColumnGroup::of('Q2', 3)->fill(Color::gray(220)),
                ),
        );
        return $doc->output();
    }

    public function testGroupedHeaderMatchesFixture(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/table/table-grouped-header.pdf');
        self::assertIsString($expected);
        self::assertSame($expected, self::buildGroupedHeader(), 'table-grouped-header.pdf diverges; regenerate if intended.');
    }

    public function testGroupedHeaderPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $p = new Process([$qpdf, '--check', __DIR__ . '/fixtures/table/table-grouped-header.pdf']);
        $p->run();
        self::assertSame(0, $p->getExitCode(), (string) $p->getOutput());
    }
}
