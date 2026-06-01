<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Image;
use DragonOfMercy\PhpPdf\Unit;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SvgTextPathMiddleTest extends TestCase
{
    private const string FIXTURE = 'svg/text/textpath-middle.pdf';

    public static function buildPdfBytes(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 120">'
            . '<defs><path id="p" d="M20,90 L280,90"/></defs>'
            . '<text font-size="18" text-anchor="middle" fill="#222222"><textPath href="#p" startOffset="150">Centered</textPath></text>'
            . '</svg>';
        $doc = new Document(Unit::MM);
        $page = $doc->addPage();
        $page->image(Image::fromBytes($svg), x: 20.0, y: 20.0, w: 120.0);
        return $doc->output();
    }

    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(__DIR__ . '/fixtures/' . self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame($expected, self::buildPdfBytes(), 'Output diverges; if intentional run php tests/Golden/regenerate.php');
    }

    public function testPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'phppdf-textpath-') . '.pdf';
        file_put_contents($tmp, self::buildPdfBytes());
        try {
            $proc = new Process([$qpdf, '--check', $tmp]);
            $proc->run();
            self::assertSame(0, $proc->getExitCode(), $proc->getOutput() . $proc->getErrorOutput());
        } finally {
            @unlink($tmp);
        }
    }
}
