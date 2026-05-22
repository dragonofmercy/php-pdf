<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\ButtonAction;
use DragonOfMercy\PhpPdf\Form\FieldAppearance;
use DragonOfMercy\PhpPdf\Form\PushButton;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class FormPushButtonTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/form-pushbutton.pdf';

    public function testFormPushButtonMatchesFixtureBytes(): void
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

    public function testPushButtonsHavePushbuttonFlag(): void
    {
        // Pushbutton flag is bit 17 (value 65536 = 1 << 16)
        $bytes = $this->buildDocument()->output();
        self::assertSame(2, substr_count($bytes, '/Ff 65536'));
    }

    public function testResetActionIsPresent(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/ResetForm', $bytes);
    }

    public function testOpenUrlActionIsPresent(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/URI (https://example.com)', $bytes);
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

        $page->field(PushButton::of(
            x: 20, y: 20, width: 40, height: 10,
            name: 'reset', caption: 'Effacer',
            action: ButtonAction::resetForm(),
            appearance: new FieldAppearance(
                borderColor: Color::rgb(0, 0, 0),
                borderWidth: 0.5,
                backgroundColor: Color::rgb(230, 230, 230),
            ),
        ));

        $page->field(PushButton::of(
            x: 70, y: 20, width: 40, height: 10,
            name: 'home', caption: 'Site web',
            action: ButtonAction::openUrl('https://example.com'),
        ));

        return $doc;
    }
}
