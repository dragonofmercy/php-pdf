<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\TextField;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class FormPasswordTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/form-password.pdf';

    public function testFormPasswordMatchesFixtureBytes(): void
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

    public function testCatalogHasAcroForm(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/AcroForm ', $bytes);
    }

    public function testPasswordFieldHasPasswordFlag(): void
    {
        // Password flag is bit 14 (value 8192 = 1 << 13). A password-only
        // text field has no other Ff bits set, so the emitted value is exactly 8192.
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/Ff 8192', $bytes);
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
        $page->field(new TextField(
            x: 20, y: 20, width: 60, height: 8,
            name: 'pwd', password: true,
        ));
        return $doc;
    }
}
