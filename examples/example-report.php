<?php

declare(strict_types=1);

/**
 * Multi-page report - end-to-end example.
 *
 * Demonstrates:
 *   - flowing Markdown with automatic page breaks (Page::markdown),
 *   - an eager header and a deferred page-numbered footer,
 *   - a sidebar table of contents (bookmarks) anchored to each section's page.
 *
 * Run it with:  php examples/example-report.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Page;
use DragonOfMercy\PhpPdf\PageMargins;

$doc = new Document(); // millimetres
$doc->metadata()->title('Quarterly Report Q2 2026')->author('Acme Studio');

$doc->setMargins(new PageMargins(top: 28.0, right: 18.0, bottom: 20.0, left: 18.0));
$doc->setAutoPageBreak(true);

$doc->setHeader(static function (Page $p): void {
    $p->setFont(Font::helvetica()->bold(), 10);
    $p->setFillColor(Color::gray(90));
    $p->text(18, 14, 'Acme Studio - Quarterly Report');
    $p->setStrokeColor(Color::gray(200))->setLineWidth(0.3);
    $p->line(18, 18, 192, 18)->stroke();
});

$doc->setFooter(static function (Page $p, int $n, int $total): void {
    $p->setFont(Font::helvetica(), 8);
    $p->setFillColor(Color::gray(120));
    $label = "Page {$n} / {$total}";
    $p->text(192 - $p->stringWidth($label), 286, $label);
});

// Each entry becomes both a bookmark and a Markdown block. Bookmarks are
// anchored to whichever page the section starts on.
$sections = [
    'Executive summary' => <<<'MD'
        # Executive summary

        Revenue grew **18%** quarter over quarter, driven by the new subscription
        tier and a healthy renewal rate. Operating margin held steady despite
        increased hiring.

        Key highlights:

        1. Subscription revenue crossed the seven-figure mark.
        2. Churn dropped to *4.1%*, the lowest on record.
        3. Two enterprise contracts signed in the final week.
        MD,
    'Financial overview' => <<<'MD'
        # Financial overview

        The table below is summarised; see the appendix for the full ledger.

        - Gross revenue: 1,240,000 EUR
        - Cost of sales: 430,000 EUR
        - Operating expenses: 510,000 EUR
        - Net profit: 300,000 EUR

        > Cash reserves now cover roughly eleven months of runway at the current
        > burn rate, giving the team room to invest in the product roadmap.

        Spending was concentrated in three areas: engineering headcount,
        infrastructure, and a modest increase in marketing to support the launch.
        MD,
    'Product roadmap' => <<<'MD'
        # Product roadmap

        The next two quarters focus on reliability and developer experience.

        - Streaming output for large documents
        - Tagged PDF for accessibility
        - A higher-level layout system

        A short illustrative snippet of the public API:

        ```
        $doc = new Document();
        $doc->addPage()->markdown($report);
        $doc->save('report.pdf');
        ```

        Each initiative ships behind a feature flag so we can measure impact
        before a broad rollout, and roll back instantly if a regression appears.
        MD,
    'Risks and outlook' => <<<'MD'
        # Risks and outlook

        The main risks remain concentration in a handful of large accounts and
        the cost of cloud infrastructure as usage scales.

        Mitigations under way:

        1. Broaden the mid-market funnel to dilute account concentration.
        2. Negotiate committed-use discounts with the infrastructure provider.
        3. Continue the subsetting and compression work to keep output small.

        Overall the outlook is positive: the pipeline is strong and the product
        is differentiated on correctness and standards conformance.
        MD,
];

$root = $doc->outline();
$page = $doc->addPage();
$page->setFont(Font::helvetica(), 11);

foreach ($sections as $title => $markdown) {
    // The section starts on whatever page is current right now.
    $root->add($title, Destination::page($doc->pageCount() - 1));
    // markdown() advances the cursor below the block by default, so sections
    // flow down the page without manual positioning.
    $doc->getCurrentPage()->markdown($markdown, width: 174.0);
    // Small gap before the next section.
    $current = $doc->getCurrentPage();
    $current->setXY(18, $current->getY() + 6);
}

$path = __DIR__ . '/example-report.pdf';
$doc->save($path);

echo "Wrote {$path}\n";
