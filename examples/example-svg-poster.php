<?php

declare(strict_types=1);

/**
 * SVG poster - end-to-end example.
 *
 * Demonstrates rendering an inline SVG as a fully vector, full-page graphic:
 * a linear-gradient background, shapes, and real selectable text. The SVG is
 * placed through Image::fromBytes(), the same path used for raster images.
 *
 * Run it with:  php examples/example-svg-poster.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;

// viewBox matches the A4 aspect ratio (210 x 297) so the poster fills the page.
$svg = <<<SVG
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 210 297">
      <defs>
        <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stop-color="#1b2a4a"/>
          <stop offset="1" stop-color="#0b1120"/>
        </linearGradient>
        <radialGradient id="glow" cx="0.5" cy="0.32" r="0.55">
          <stop offset="0" stop-color="#3b82f6" stop-opacity="0.9"/>
          <stop offset="1" stop-color="#3b82f6" stop-opacity="0"/>
        </radialGradient>
      </defs>

      <rect x="0" y="0" width="210" height="297" fill="url(#bg)"/>
      <circle cx="105" cy="95" r="70" fill="url(#glow)"/>

      <circle cx="105" cy="95" r="42" fill="none" stroke="#60a5fa" stroke-width="1.5"/>
      <polygon points="105,62 133,112 77,112" fill="#f8fafc"/>

      <text x="105" y="180" font-family="Helvetica" font-size="26" font-weight="bold" fill="#f8fafc" text-anchor="middle">phppdf</text>
      <text x="105" y="196" font-family="Helvetica" font-size="9" fill="#94a3b8" text-anchor="middle">Pure PHP PDF generation</text>

      <line x1="55" y1="214" x2="155" y2="214" stroke="#334155" stroke-width="0.6"/>
      <text x="105" y="232" font-family="Helvetica" font-size="8" fill="#cbd5e1" text-anchor="middle">Vector graphics - infinite zoom - selectable text</text>
    </svg>
    SVG;

$doc = new Document(); // millimetres
$doc->metadata()->title('phppdf poster')->author('Acme Studio');

$page = $doc->addPage();
$page->image(Image::fromBytes($svg), x: 0.0, y: 0.0, w: 210.0); // height derived from the viewBox aspect

$path = __DIR__ . '/example-svg-poster.pdf';
$doc->save($path);

echo "Wrote {$path}\n";
