<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\FieldAppearance;
use DragonOfMercy\PhpPdf\Form\SignatureField;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class FormSignatureTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/form/signature.pdf';

    public function testFormSignatureMatchesFixtureBytes(): void
    {
        $bytes = $this->buildDocument()->output();
        $expected = file_get_contents(self::FIXTURE);
        self::assertNotFalse($expected, 'fixture missing - run tests/Golden/regenerate.php');
        self::assertSame($expected, $bytes, 'rendered bytes diverge from fixture');
    }

    public function testQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf not on PATH');
        }
        $process = new Process([$qpdf, '--check', self::FIXTURE]);
        $process->run();
        self::assertSame(0, $process->getExitCode(), 'qpdf --check failed: ' . $process->getOutput() . $process->getErrorOutput());
    }

    public function testSigFieldAndFlagsPresent(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/FT /Sig', $bytes);
        self::assertStringContainsString('/SigFlags 3', $bytes);
    }

    public function testDeterminism(): void
    {
        $a = $this->buildDocument()->output();
        $b = $this->buildDocument()->output();
        self::assertSame($a, $b);
    }

    public function buildDocument(): Document
    {
        $doc = new Document();
        $page = $doc->addPage();

        $page->field(SignatureField::visible(
            x: 20, y: 20, width: 80, height: 30, name: 'visible_sig',
            appearance: new FieldAppearance(
                borderColor: Color::rgb(0, 0, 0),
                borderWidth: 0.5,
            ),
        ));

        $page->field(SignatureField::invisible(name: 'invisible_sig'));

        return $doc;
    }
}
