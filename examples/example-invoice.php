<?php

declare(strict_types=1);

/**
 * Commercial invoice - end-to-end example.
 *
 * Combines several subsystems in one realistic document:
 *   - document metadata,
 *   - page margins with an eager header and a deferred, page-numbered footer,
 *   - a data table (fixed + fill columns, right-aligned money, zebra striping),
 *   - a totals block drawn with plain cells anchored to the table cursor.
 *
 * Run it with:  php examples/example-invoice.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\PageMargins;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Table\TableBorders;
use DragonOfMercy\PhpPdf\Table\TableStyle;
use DragonOfMercy\PhpPdf\TextAlign;
use DragonOfMercy\PhpPdf\VerticalAlign;

$money = static fn (float $value): string => number_format($value, 2, '.', ',') . ' EUR';

// --- Data -------------------------------------------------------------------

$items = [
    ['desc' => 'Web design - landing page',          'qty' => 1,  'unit' => 1200.00],
    ['desc' => 'Front-end development (day rate)',    'qty' => 8,  'unit' => 480.00],
    ['desc' => 'Copywriting and proofreading',        'qty' => 3,  'unit' => 220.00],
    ['desc' => 'Stock photography licence',           'qty' => 12, 'unit' => 18.50],
    ['desc' => 'Hosting and setup (first year)',      'qty' => 1,  'unit' => 350.00],
];

$subtotal = array_sum(array_map(static fn (array $i): float => $i['qty'] * $i['unit'], $items));
$vatRate  = 0.20;
$vat      = $subtotal * $vatRate;
$total    = $subtotal + $vat;

// --- Document ---------------------------------------------------------------

$doc = new Document(); // millimetres
$doc->metadata()
    ->title('Invoice 2026-001')
    ->author('Acme Studio')
    ->subject('Invoice')
    ->creator('phppdf examples');

$doc->setMargins(new PageMargins(top: 46.0, right: 15.0, bottom: 24.0, left: 15.0));
$doc->setAutoPageBreak(true); // break threshold follows the bottom margin

$doc->setHeader(static function (Page $p): void {
    $p->setFont(Font::helvetica()->bold(), 16);
    $p->text(15, 16, 'ACME STUDIO');

    $p->setFont(Font::helvetica(), 9);
    $p->text(15, 22, '12 Market Street, 1000 Lausanne, Switzerland');
    $p->text(15, 26, 'hello@acme.example - VAT CHE-123.456.789');

    $p->setStrokeColor(Color::gray(180))->setLineWidth(0.3);
    $p->line(15, 32, 195, 32)->stroke();
});

$doc->setFooter(static function (Page $p, int $n, int $total): void {
    $p->setStrokeColor(Color::gray(200))->setLineWidth(0.3);
    $p->line(15, 280, 195, 280)->stroke();

    $p->setFont(Font::helvetica(), 8);
    $p->setFillColor(Color::gray(120));
    $p->text(15, 286, 'Thank you for your business.');

    // Right-align the page number with text() so the footer never triggers an
    // auto page-break (cell() near the bottom margin would).
    $label = "Page {$n} / {$total}";
    $p->text(195 - $p->stringWidth($label), 286, $label);
});

$page = $doc->addPage();

// --- Invoice heading --------------------------------------------------------

$page->setFont(Font::helvetica()->bold(), 22);
$page->text(15, 56, 'INVOICE');

// Two-column layout: labels and values each at a fixed x so the values line up
// (a proportional font cannot be aligned with spaces).
$page->setFont(Font::helvetica(), 10);
$metaLabelX = 140;
$metaValueX = 170;
$page->text($metaLabelX, 52, 'Invoice no.');
$page->text($metaValueX, 52, '2026-001');
$page->text($metaLabelX, 58, 'Date');
$page->text($metaValueX, 58, '2026-06-03');
$page->text($metaLabelX, 64, 'Due date');
$page->text($metaValueX, 64, '2026-07-03');

$page->setFont(Font::helvetica()->bold(), 10);
$page->text(15, 74, 'Bill to');
$page->setFont(Font::helvetica(), 10);
$page->text(15, 80, 'Globex Corporation');
$page->text(15, 85, 'Attn. Accounts Payable');
$page->text(15, 90, '500 Industrial Avenue, 8000 Zurich');

// --- Line-item table --------------------------------------------------------

$columns = [
    Column::of('idx', '#')->width(10)->align(TextAlign::CENTER),
    Column::of('desc', 'Description')->fill(),
    Column::of('qty', 'Qty')->width(18)->align(TextAlign::RIGHT),
    Column::of('unit', 'Unit price')->width(32)->align(TextAlign::RIGHT),
    Column::of('amount', 'Amount')->width(34)->align(TextAlign::RIGHT),
];

$rows = [];
foreach ($items as $i => $item) {
    $rows[] = [
        'idx'    => (string) ($i + 1),
        'desc'   => $item['desc'],
        'qty'    => (string) $item['qty'],
        'unit'   => $money($item['unit']),
        'amount' => $money($item['qty'] * $item['unit']),
    ];
}

$style = TableStyle::default()
    ->withHeader(fill: Color::gray(60), bold: true, textColor: Color::rgb(255, 255, 255))
    ->withBorder(TableBorders::HORIZONTAL)
    ->withZebra(Color::rgb(255, 255, 255), Color::gray(245));

$result = $page->table($columns, $rows, x: 15, y: 100, width: 180, style: $style);

// --- Totals block -----------------------------------------------------------

$page = $result->page;
$labelX = 15 + 180 - 66;   // align the totals under the last two columns
$valueX = $labelX + 34;
$y = $result->y + 4;

$line = static function (Page $p, float $y, string $label, string $value, bool $emphasis) use ($labelX, $valueX): void {
    $font = $emphasis ? Font::helvetica()->bold() : Font::helvetica();
    $p->setFont($font, 10);
    // On the emphasised (total) row the top rule spans both the label and value
    // columns, so it sits above "Total due" as well as the amount.
    $topRule = $emphasis ? Border::sides(top: true) : null;
    $p->cell(x: $labelX, y: $y, w: 34, h: 7, text: $label, border: $topRule, align: TextAlign::RIGHT, verticalAlign: VerticalAlign::MIDDLE);
    $p->cell(
        x: $valueX, y: $y, w: 32, h: 7,
        text: $value,
        border: $topRule,
        align: TextAlign::RIGHT,
        verticalAlign: VerticalAlign::MIDDLE,
    );
};

$line($page, $y, 'Subtotal', $money($subtotal), false);
$line($page, $y + 7, 'VAT (20%)', $money($vat), false);
$line($page, $y + 14, 'Total due', $money($total), true);

$page->setFont(Font::helvetica(), 9);
$page->setFillColor(Color::gray(90));
$page->text(15, $y + 28, 'Payment within 30 days to IBAN CH00 0000 0000 0000 0000 0, ref. 2026-001.');

// --- Output -----------------------------------------------------------------

$path = __DIR__ . '/example-invoice.pdf';
$doc->save($path);

echo "Wrote {$path}\n";
