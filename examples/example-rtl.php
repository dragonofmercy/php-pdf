<?php

declare(strict_types=1);

/**
 * Right-to-left text - end-to-end example.
 *
 * Demonstrates:
 *   - a document-wide RTL base direction (Document::setBaseDirection),
 *   - Hebrew, Arabic, and Persian (Farsi) on one page with a single
 *     RTL-capable font (GNU FreeSerif), including automatic Arabic / Persian
 *     cursive shaping and the lam-alef ligature,
 *   - mixed LTR / RTL runs reordered by the Unicode bidi algorithm,
 *   - an RTL table column.
 *
 * Source text is written with \u{...} escapes (ASCII source) and a
 * transliteration in the trailing comment.
 *
 * Run it with:  php examples/example-rtl.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Table\Cell;
use DragonOfMercy\PhpPdf\Table\Column;
use DragonOfMercy\PhpPdf\Text\Direction;
use DragonOfMercy\PhpPdf\TextAlign;

$doc = new Document(); // millimetres
$doc->metadata()->title('Right-to-left showcase');

// One font covers all three scripts here: GNU FreeSerif ships the Hebrew block
// and the Arabic presentation forms (including the Persian-specific letters),
// which is what the shaper needs. Every RTL document needs an embedded font -
// the Standard-14 fonts do not carry these glyphs.
$doc->registerFontFamily('serif', regular: __DIR__ . '/../tests/Golden/assets/fonts/FreeSerif.ttf');

// Make the whole document right-to-left. Every cell() / table() / markdown()
// call inherits this unless it passes an explicit direction:.
$doc->setBaseDirection(Direction::RTL);

$page = $doc->addPage();

// Latin heading, forced LTR inside the otherwise RTL document.
$page->setFont(Font::helvetica()->bold(), 14);
$page->cell(x: 20, y: 22, w: 170, text: 'RTL showcase', direction: Direction::LTR, align: TextAlign::LEFT);

$page->setFont(Font::custom('serif'), 18);

// Hebrew - "Shalom Olam" (hello world). RTL cells right-align by default.
$page->cell(x: 20, y: 36, w: 170, text: "\u{05E9}\u{05DC}\u{05D5}\u{05DD} \u{05E2}\u{05D5}\u{05DC}\u{05DD}");

// Arabic - "marhaban" (hello). Letters join; the lam-alef ligature forms automatically.
$page->cell(x: 20, y: 50, w: 170, text: "\u{0645}\u{0631}\u{062D}\u{0628}\u{0627}");

// Persian / Farsi - "salam donya" (hello world). Same shaper; "donya" uses the Farsi yeh.
$page->cell(x: 20, y: 64, w: 170, text: "\u{0633}\u{0644}\u{0627}\u{0645} \u{062F}\u{0646}\u{06CC}\u{0627}");

// Mixed LTR / RTL on one line - the bidi algorithm orders the runs. With an RTL
// base, "PHP" (the first logical word) stays on the right and the Arabic word is
// placed to its left.
$page->setFont(Font::custom('serif'), 14);
$page->cell(x: 20, y: 80, w: 170, text: "PHP \u{0645}\u{0631}\u{062D}\u{0628}\u{0627}");

// A table whose last column holds RTL data cells (greetings per language).
$page->setFont(Font::custom('serif'), 12);
$columns = [
    Column::of('lang', 'Language')->width(45)->align(TextAlign::LEFT),
    Column::of('greet', 'Greeting')->fill()->align(TextAlign::RIGHT),
];
$rows = [
    ['lang' => 'Hebrew',  'greet' => Cell::of("\u{05E9}\u{05DC}\u{05D5}\u{05DD}")->direction(Direction::RTL)],         // Shalom
    ['lang' => 'Arabic',  'greet' => Cell::of("\u{0645}\u{0631}\u{062D}\u{0628}\u{0627}")->direction(Direction::RTL)],  // marhaban
    ['lang' => 'Persian', 'greet' => Cell::of("\u{0633}\u{0644}\u{0627}\u{0645}")->direction(Direction::RTL)],          // salam
];
$page->table($columns, $rows, x: 20, y: 96, width: 170);

$path = __DIR__ . '/example-rtl.pdf';
$doc->save($path);

echo "Wrote {$path}\n";
