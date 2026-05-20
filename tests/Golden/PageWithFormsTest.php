<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\Combobox;
use DragonOfMercy\PhpPdf\Form\Listbox;
use DragonOfMercy\PhpPdf\Form\Radio;
use DragonOfMercy\PhpPdf\Form\TextField;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageWithFormsTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/page-with-forms.pdf';

    public function testPageWithFormsMatchesFixtureBytes(): void
    {
        $expected = file_get_contents(self::FIXTURE);
        self::assertIsString($expected, 'Golden fixture missing - regenerate with tests/Golden/regenerate.php');
        self::assertSame(
            $expected,
            $this->buildDocument()->output(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPageWithFormsPassesQpdfCheck(): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping structural validation.');
        }
        $process = new Process([$qpdf, '--check', self::FIXTURE]);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            "qpdf --check failed:\nstdout:\n" . $process->getOutput() . "\nstderr:\n" . $process->getErrorOutput(),
        );
    }

    public function testCatalogContainsAcroForm(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/AcroForm ', $bytes);
    }

    public function testAcroFormHasNeedAppearancesAndDA(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/NeedAppearances true', $bytes);
        self::assertStringContainsString('/DA (0 g /Helv 10 Tf)', $bytes);
    }

    public function testDRContainsHelv(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/Helv', $bytes);
    }

    public function testRadioGroupHasParentWithThreeKids(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertMatchesRegularExpression('~/Kids \[\d+ 0 R \d+ 0 R \d+ 0 R\]~', $bytes);
    }

    public function testCheckboxHasAPNOnOff(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/AP', $bytes);
        self::assertStringContainsString('/N', $bytes);
        self::assertStringContainsString('/On', $bytes);
        self::assertStringContainsString('/Off', $bytes);
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
        $page->setFont(Font::helvetica(), 10);

        $page->field(new TextField(50.0, 50.0, 80.0, 8.0, name: 'firstname', value: 'Bob', required: true));
        $page->field(new TextField(50.0, 70.0, 80.0, 30.0, name: 'comment', multiline: true));

        $page->field(new Checkbox(50.0, 110.0, 5.0, 5.0, name: 'agree'));

        $page->field(new Radio(50.0, 130.0, 5.0, 5.0, group: 'civility', value: 'mr', checked: true));
        $page->field(new Radio(50.0, 140.0, 5.0, 5.0, group: 'civility', value: 'mrs'));
        $page->field(new Radio(50.0, 150.0, 5.0, 5.0, group: 'civility', value: 'other'));

        $page->field(new Combobox(
            50.0,
            165.0,
            80.0,
            8.0,
            name: 'country',
            options: ['fr' => 'France', 'ch' => 'Suisse', 'be' => 'Belgique'],
            value: 'ch',
        ));

        $page->field(new Listbox(
            50.0,
            180.0,
            80.0,
            30.0,
            name: 'interests',
            options: ['music', 'sport', 'code'],
            value: ['sport', 'code'],
            multiSelect: true,
        ));

        return $doc;
    }
}
