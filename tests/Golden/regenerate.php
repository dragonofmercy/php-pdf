<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PhpPdf\Document;

$fixturesDir = __DIR__ . '/fixtures';
if (!is_dir($fixturesDir)) {
    mkdir($fixturesDir, 0755, true);
}

$doc = new Document();
$doc->addPage();
$doc->save($fixturesDir . '/empty-page.pdf');

echo "Regenerated empty-page.pdf\n";
