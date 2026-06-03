<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\NextPosition;
use DragonOfMercy\PhpPdf\Tests\Support\TestImageFactory;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageImageFlowTest extends TestCase
{
    public function testImageFlowMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/page/image-flow.pdf');
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $this->buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testImageFlowPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }

        $process = new Process([$qpdf, '--check', __DIR__ . '/fixtures/page/image-flow.pdf']);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }

    private function buildPdfBytes(): string
    {
        $doc = new Document(Unit::PT);
        $page = $doc->addPage();
        $thumb = Image::fromBytes(TestImageFactory::pngPalette(width: 8, height: 8));

        $page->setXY(50, 50);
        $page->image($thumb, w: 80, h: 60);
        $page->image($thumb, w: 80, h: 60, ln: NextPosition::NEWLINE);
        $page->image($thumb, w: 80, h: 60);

        return $doc->output();
    }
}
