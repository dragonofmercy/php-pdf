<?php

declare(strict_types=1);

/**
 * Quarterly financials - cell spanning example.
 *
 * Showcases the two table spanning features:
 *   - grouped headers: a "H1 2026" / "H2 2026" band sits above the four quarter
 *     columns, while a spacer group lets the leading "Item" header rise across
 *     both header bands;
 *   - data colspan: full-width section banners and a closing note span every
 *     column via Cell::colSpan().
 *
 * Run it with:  php examples/example-financials.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Table\Cell;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Table\ColumnGroup;
use DragonOfMercy\PhpPdf\Table\TableBorders;
use DragonOfMercy\PhpPdf\Table\TableStyle;
use DragonOfMercy\PhpPdf\TextAlign;

$num = static fn (int $value): string => number_format($value, 0, '.', ',');

// --- Data -------------------------------------------------------------------
// Amounts in thousands of EUR, per quarter (q1..q4).

$section = static fn (string $title): array => ['item' => Cell::of($title)->colSpan(5)->bold()->fill(Color::gray(225))];

$line = static function (string $item, int $q1, int $q2, int $q3, int $q4) use ($num): array {
    return ['item' => $item, 'q1' => $num($q1), 'q2' => $num($q2), 'q3' => $num($q3), 'q4' => $num($q4)];
};

$rows = [
    $section('Revenue'),
    $line('Product sales',      820,  910, 1050, 1180),
    $line('Services',           340,  365,  390,  410),
    $line('Subscriptions',      210,  245,  280,  320),

    $section('Operating costs'),
    $line('Salaries',           560,  575,  590,  605),
    $line('Marketing',          180,  220,  150,  240),
    $line('Infrastructure',      95,  100,  110,  118),

    // A full-width closing note - colSpan across every column.
    ['item' => Cell::of('Figures are unaudited and expressed in thousands of EUR.')->colSpan(5)->align(TextAlign::CENTER)],
];

// --- Document ---------------------------------------------------------------

$doc = new Document(); // millimetres
$doc->metadata()
    ->title('Quarterly financials 2026')
    ->author('Acme Studio')
    ->creator('phppdf examples');

$page = $doc->addPage();

$page->setFont(Font::helvetica()->bold(), 18);
$page->text(15, 24, 'Quarterly financials - 2026');

$page->setFont(Font::helvetica(), 10);
$page->text(15, 31, 'Revenue and operating costs by quarter (thousands of EUR).');

// --- Table with grouped headers and spanning rows ---------------------------

$columns = [
    Column::of('item', 'Item')->fill(),
    Column::of('q1', 'Q1')->width(25)->align(TextAlign::RIGHT),
    Column::of('q2', 'Q2')->width(25)->align(TextAlign::RIGHT),
    Column::of('q3', 'Q3')->width(25)->align(TextAlign::RIGHT),
    Column::of('q4', 'Q4')->width(25)->align(TextAlign::RIGHT),
];

$style = TableStyle::default()
    ->withBorder(TableBorders::GRID)
    ->withHeader(fill: Color::gray(60), bold: true, textColor: Color::rgb(255, 255, 255))
    ->withColumnGroups(
        ColumnGroup::spacer(),                                                          // "Item" rises across both bands
        ColumnGroup::of('H1 2026', 2)->fill(Color::gray(90))->textColor(Color::rgb(255, 255, 255)),
        ColumnGroup::of('H2 2026', 2)->fill(Color::gray(90))->textColor(Color::rgb(255, 255, 255)),
    );

$page->table($columns, $rows, x: 15, y: 40, width: 180, style: $style);

// --- Output -----------------------------------------------------------------

$path = __DIR__ . '/example-financials.pdf';
$doc->save($path);

echo "Wrote {$path}\n";
