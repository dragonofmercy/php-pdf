<?php

declare(strict_types=1);

/**
 * Shipping labels - end-to-end example.
 *
 * Demonstrates:
 *   - custom page dimensions, one small label per page,
 *   - a Code 128 tracking barcode and a QR code on the same label,
 *   - mixing text and barcodes in a tight layout.
 *
 * Run it with:  php examples/example-labels.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DragonOfMercy\PhpPdf\Barcode\Code128;
use DragonOfMercy\PhpPdf\Barcode\QrCode;
use DragonOfMercy\PhpPdf\Border;
use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;

$shipments = [
    ['name' => 'Globex Corporation', 'addr' => '500 Industrial Avenue',   'city' => '8000 Zurich, CH',  'track' => 'SHIP-2026-0001'],
    ['name' => 'Initech LLC',        'addr' => '42 Cubicle Lane',          'city' => '1000 Brussels, BE', 'track' => 'SHIP-2026-0002'],
    ['name' => 'Soylent Foods',      'addr' => '7 Greenfield Road',        'city' => '75001 Paris, FR',   'track' => 'SHIP-2026-0003'],
];

$doc = new Document(); // millimetres
$doc->metadata()->title('Shipping labels')->author('Acme Studio');

foreach ($shipments as $s) {
    $page = $doc->addPage([100.0, 50.0]); // 100 x 50 mm label

    // Thin frame around the whole label.
    $page->cell(x: 2, y: 2, w: 96, h: 46, text: '', border: Border::all()->withWidth(0.3));

    $page->setFont(Font::helvetica()->bold(), 11);
    $page->text(6, 10, $s['name']);

    $page->setFont(Font::helvetica(), 8);
    $page->setFillColor(Color::gray(60));
    $page->text(6, 16, $s['addr']);
    $page->text(6, 21, $s['city']);

    // Tracking barcode (Code 128 prints the human-readable number itself).
    $page->barcode(Code128::of($s['track']), x: 6, y: 30, w: 58.0, h: 12.0);

    // QR code carrying the same tracking reference.
    $page->barcode(QrCode::of('TRACK:' . $s['track']), x: 72.0, y: 28.0, w: 22.0);
}

$path = __DIR__ . '/example-labels.pdf';
$doc->save($path);

echo "Wrote {$path}\n";
