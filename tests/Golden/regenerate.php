<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PhpPdf\Document;

$fixturesDir = __DIR__ . '/fixtures';
if (!is_dir($fixturesDir)) {
    mkdir($fixturesDir, 0755, true);
}

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
