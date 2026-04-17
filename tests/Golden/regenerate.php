<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PhpPdf\Color;
use PhpPdf\Document;
use PhpPdf\Font;

$fixturesDir = __DIR__ . '/fixtures';
if (!is_dir($fixturesDir)) {
    mkdir($fixturesDir, 0755, true);
}

$zeros = fn (int $n): string => str_repeat("\x00", $n);

// Fixture 1: empty page without metadata (Phase 0 compat)
$doc = new Document();
$doc->addPage();
$doc->save($fixturesDir . '/empty-page.pdf');
echo "Regenerated empty-page.pdf\n";

// Fixture 2: document with metadata (Phase 1a)
$doc = new Document();
$doc->metadata()
    ->title('Test')
    ->author('User')
    ->subject('Phase 1a')
    ->keywords('phppdf, test')
    ->creator('Test Suite')
    ->creationDate(new DateTimeImmutable('2026-01-01T12:00:00+00:00'))
    ->documentId('abcdef0123456789abcdef0123456789');
$doc->addPage();
$doc->save($fixturesDir . '/document-with-metadata.pdf');
echo "Regenerated document-with-metadata.pdf\n";

// Fixture 3: encrypted document (Phase 1b)
$doc = new Document();
$doc->metadata()
    ->title('Confidential')
    ->author('User')
    ->creationDate(new DateTimeImmutable('2026-01-01T12:00:00+00:00'))
    ->documentId('abcdef0123456789abcdef0123456789');
$doc->encryption()
    ->userPassword('user')
    ->ownerPassword('owner')
    ->allowPrint()
    ->allowCopy()
    ->withRandomSource($zeros);
$doc->addPage();
$doc->save($fixturesDir . '/encrypted-document.pdf');
echo "Regenerated encrypted-document.pdf\n";

// Fixture 4: page with graphics (Phase 2a)
$doc = new Document();
$page = $doc->addPage();

$page->setStrokeColor(Color::hex('#ff0000'))
     ->setLineWidth(1)
     ->rect(20, 20, 100, 50)
     ->stroke();

$page->setFillColor(Color::rgb(0, 0, 255))
     ->circle(200, 200, 40)
     ->fill();

$page->setStrokeColor(Color::gray(128))
     ->setLineWidth(2)
     ->line(0, 0, 595, 842)
     ->stroke();

$page->setFillColor(Color::hex('#00aa00'))
     ->path()
     ->moveTo(300, 500)
     ->lineTo(400, 500)
     ->lineTo(350, 450)
     ->close()
     ->fill();

$page->save()
     ->translate(450, 100)
     ->rotate(30)
     ->setFillColor(Color::hex('#ff8800'))
     ->rect(-10, -10, 20, 20)
     ->fill();
$page->restore();

$doc->save($fixturesDir . '/page-with-graphics.pdf');
echo "Regenerated page-with-graphics.pdf\n";

// Fixture 5: page with text (Phase 2b)
$doc = new Document();
$page = $doc->addPage();

$page->setFont(Font::helvetica()->bold(), 18);
$page->text(50, 50, 'Hello World');

$page->setFont(Font::times()->italic(), 12);
$page->text(50, 100, 'Résumé — café, naïveté, œuvre');

$page->setFont(Font::courier(), 10);
$page->text(50, 150, "Line 1\nLine 2\nLine 3");

$page->setFont(Font::helvetica(), 14);
$page->text(50, 220, 'Prix : 19,99 €');

$doc->save($fixturesDir . '/page-with-text.pdf');
echo "Regenerated page-with-text.pdf\n";
