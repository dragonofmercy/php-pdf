<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Modify\PageOperations\DestinationTarget;
use DragonOfMercy\PhpPdf\Outline\Destination;
use DragonOfMercy\PhpPdf\Outline\Link;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageOperationsTest extends TestCase
{
    private static function sourceWithOutlinesAndLinks(): string
    {
        $doc = new Document(Unit::PT);
        $p1 = $doc->addPage();
        $p1->link(50, 90, 200, 14, Link::url('https://example.com'));         // URI (keep)
        $p1->link(50, 130, 200, 14, Link::destination(Destination::page(1))); // -> page 2 (deleted)
        $doc->addPage();
        $p3 = $doc->addPage();
        $p3->link(50, 130, 200, 14, Link::destination(Destination::page(0))); // -> page 1 (kept)

        $root = $doc->outline();
        $root->add('Chapter 1', Destination::page(0));
        $root->add('Chapter 2', Destination::page(1)); // -> page 2 (deleted)
        $root->add('Chapter 3', Destination::page(2));
        return $doc->output();
    }

    public function testDeletePagePrunesDanglingRefsAndKeepsOthers(): void
    {
        $src = self::sourceWithOutlinesAndLinks();
        $before = PdfReader::fromBytes($src);
        $deletedPageObj = $before->page(2)->objectNumber;
        self::assertNotNull($deletedPageObj);

        $out = PdfEditor::fromBytes($src)->deletePages(2)->output();
        $reader = PdfReader::fromBytes($out);

        self::assertSame(2, $reader->pageCount());
        self::assertFalse($this->anyDestinationTargets($reader, $deletedPageObj), 'A dangling reference to the deleted page survived');
        self::assertStringContainsString('/URI', $out, 'The external URI link must be preserved');
    }

    public function testReorderKeepsAllReferencesResolvable(): void
    {
        $src = self::sourceWithOutlinesAndLinks();
        $out = PdfEditor::fromBytes($src)->reorderPages([3, 2, 1])->output();
        $reader = PdfReader::fromBytes($out);
        self::assertSame(3, $reader->pageCount());
        self::assertStringContainsString('/S /GoTo', $out);
        self::assertStringContainsString('/URI', $out);
    }

    public function testDeleteOnEncryptedSourcePrunesRefs(): void
    {
        $doc = new Document(Unit::PT);
        $doc->encryption()->userPassword('u')->ownerPassword('o');
        $doc->addPage();
        $doc->addPage();
        $doc->addPage();
        $root = $doc->outline();
        $root->add('Chapter 2', Destination::page(1));
        $src = $doc->output();

        $before = PdfReader::fromBytes($src, 'u');
        $deletedPageObj = $before->page(2)->objectNumber;
        self::assertNotNull($deletedPageObj);

        $out = PdfEditor::fromBytes($src, 'u')->deletePages(2)->output();
        $reader = PdfReader::fromBytes($out, 'u');
        self::assertSame(2, $reader->pageCount());
        self::assertFalse($this->anyDestinationTargets($reader, $deletedPageObj));
    }

    public function testQpdfCheckOnDeletedOutput(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $out = PdfEditor::fromBytes(self::sourceWithOutlinesAndLinks())->deletePages(2)->reorderPages([3, 1])->output();
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf_pageops_');
        self::assertIsString($tmp);
        try {
            file_put_contents($tmp, $out);
            $process = new Process([$qpdf, '--check', $tmp]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), 'qpdf --check failed: ' . $process->getOutput() . $process->getErrorOutput());
        } finally {
            @unlink($tmp);
        }
    }

    private function anyDestinationTargets(PdfReader $reader, int $pageObj): bool
    {
        $outlines = $reader->catalog()->get(Name::of('Outlines'));
        if ($outlines instanceof PdfReference) {
            $root = $reader->resolve($outlines);
            if ($root instanceof Dictionary && $this->outlineTargets($reader, $root->get(Name::of('First')), $pageObj, 0)) {
                return true;
            }
        }
        for ($i = 1; $i <= $reader->pageCount(); $i++) {
            $annots = $reader->resolve($reader->page($i)->dict->get(Name::of('Annots')) ?? PdfNull::instance());
            if (!$annots instanceof PdfArray) {
                continue;
            }
            foreach ($annots->elements() as $el) {
                $annot = $reader->resolve($el);
                if (!$annot instanceof Dictionary) {
                    continue;
                }
                $dest = $annot->get(Name::of('Dest')) ?? $annot->get(Name::of('A'));
                if ($dest !== null && DestinationTarget::pageObjectNumber($dest, $reader) === $pageObj) {
                    return true;
                }
            }
        }
        return false;
    }

    private function outlineTargets(PdfReader $reader, ?PdfObject $first, int $pageObj, int $depth): bool
    {
        if ($depth > 1000 || !$first instanceof PdfReference) {
            return false;
        }
        $cursor = $first;
        while ($cursor instanceof PdfReference) {
            $item = $reader->resolve($cursor);
            if (!$item instanceof Dictionary) {
                break;
            }
            $dest = $item->get(Name::of('Dest')) ?? $item->get(Name::of('A'));
            if ($dest !== null && DestinationTarget::pageObjectNumber($dest, $reader) === $pageObj) {
                return true;
            }
            if ($this->outlineTargets($reader, $item->get(Name::of('First')), $pageObj, $depth + 1)) {
                return true;
            }
            $cursor = $item->get(Name::of('Next'));
        }
        return false;
    }
}
