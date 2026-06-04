<?php

declare(strict_types=1);

/**
 * Product catalogue - end-to-end example.
 *
 * Demonstrates:
 *   - a card grid laid out with cells,
 *   - an embedded raster image reused across every card (one embed, N placements),
 *   - clickable link annotations over each card.
 *
 * The thumbnail is an inline base64 PNG so the example needs no external assets.
 *
 * Run it with:  php examples/example-catalog.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Outline\Link;

// A 72x72 PNG generated once with gd; embedded so the example is self-contained.
$thumbBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAEgAAABICAIAAADajyQQAAAACXBIWXMAAA7EAAAOxAGVKw4b'
    . 'AAAAbklEQVRoge3PQQ3AMADEsJs6/s/hHQ5XsUIgz/ad7b7enV2pMU1jmsY0jWka0zSmaUzT'
    . 'mKYxTWOaxjSNaRrTNKZpTNOYpjFNY5rGNI1pGtM0pmlM05imMU1jmsY0jWka0zSmaUzTmKYx'
    . 'TWOaxjQ/7Q4DXqXmHmwAAAAASUVORK5CYII=';

$thumb = Image::fromBase64($thumbBase64);

$products = [
    ['name' => 'Aurora Desk Lamp',   'price' => '79.00 EUR',  'url' => 'https://shop.example/p/aurora'],
    ['name' => 'Nimbus Headphones',  'price' => '149.00 EUR', 'url' => 'https://shop.example/p/nimbus'],
    ['name' => 'Terra Notebook',     'price' => '12.50 EUR',  'url' => 'https://shop.example/p/terra'],
    ['name' => 'Vela Water Bottle',  'price' => '24.00 EUR',  'url' => 'https://shop.example/p/vela'],
    ['name' => 'Orbit Wall Clock',   'price' => '39.00 EUR',  'url' => 'https://shop.example/p/orbit'],
    ['name' => 'Pulse Smart Plug',   'price' => '21.00 EUR',  'url' => 'https://shop.example/p/pulse'],
];

$doc = new Document(); // millimetres
$doc->metadata()->title('Product catalogue')->author('Acme Studio');

$page = $doc->addPage();
$page->setFont(Font::helvetica()->bold(), 18);
$page->text(15, 22, 'Catalogue');

// Grid geometry: 2 columns, cards 88 x 46 mm.
$cardW = 88.0;
$cardH = 46.0;
$gapX = 4.0;
$gapY = 6.0;
$startX = 15.0;
$startY = 32.0;

foreach ($products as $i => $product) {
    $col = $i % 2;
    $row = intdiv($i, 2);
    $x = $startX + $col * ($cardW + $gapX);
    $y = $startY + $row * ($cardH + $gapY);

    // Card background.
    $page->cell(x: $x, y: $y, w: $cardW, h: $cardH, text: '', border: Border::all()->withWidth(0.3), fill: Color::gray(250));

    // Thumbnail on the left of the card.
    $page->image($thumb, x: $x + 4, y: $y + 4, w: 38.0, h: 38.0, ln: NextPosition::NONE);

    // Text block on the right.
    $textX = $x + 48;
    $textW = $cardW - 48 - 4; // room from the thumbnail to the card's right edge
    // Name as a cell so long names wrap inside the card instead of overflowing.
    // padding: 0 keeps the text's left edge flush with the price/link below.
    $page->setFont(Font::helvetica()->bold(), 12);
    $page->cell(x: $textX, y: $y + 7, w: $textW, text: $product['name'], textColor: Color::gray(20), padding: 0);

    $page->setFont(Font::helvetica(), 11);
    $page->setFillColor(Color::rgb(0, 110, 70));
    $page->text($textX, $y + 24, $product['price']);

    $page->setFont(Font::helvetica(), 9);
    $page->setFillColor(Color::rgb(40, 90, 200));
    $page->text($textX, $y + 36, 'View product');

    // Whole card is a clickable link to the product page.
    $page->link($x, $y, $cardW, $cardH, Link::url($product['url']));
}

$path = __DIR__ . '/example-catalog.pdf';
$doc->save($path);

echo "Wrote {$path}\n";
