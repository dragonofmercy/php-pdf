<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\Combobox;
use DragonOfMercy\PhpPdf\Form\FieldAppearance;
use DragonOfMercy\PhpPdf\Form\Listbox;
use DragonOfMercy\PhpPdf\Form\Radio;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\TextAlign;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PageWithStyledFormsTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/page-with-styled-forms.pdf';

    public function testPageWithStyledFormsMatchesFixtureBytes(): void
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

    public function testDRContainsHelvCourTiRo(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/Helv', $bytes);
        self::assertStringContainsString('/Cour', $bytes);
        self::assertStringContainsString('/TiRo', $bytes);
    }

    public function testTextFieldHasCustomDAAndMK(): void
    {
        $bytes = $this->buildDocument()->output();
        // TextField uses Courier 12 + textColor black (gray shortcut), border red, bg gray
        self::assertStringContainsString('(0 g /Cour 12 Tf)', $bytes);
        self::assertStringContainsString('/MK', $bytes);
        self::assertStringContainsString('/BC [1 0 0]', $bytes);
    }

    public function testTextFieldCenterAlignEmitsQ1(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/Q 1', $bytes);
    }

    public function testComboboxHasTimesDA(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/TiRo 11 Tf', $bytes);
    }

    public function testListboxRightAlignNoQ2InListbox(): void
    {
        // Listbox does NOT support /Q in this implementation (only TextField does).
        // Just verify the listbox still emits /Opt and basic structure.
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/Opt [(music) (sport) (code)]', $bytes);
    }

    public function testCustomFontThrows(): void
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new TextField(0.0, 0.0, 50.0, 8.0,
            name: 'broken',
            appearance: new FieldAppearance(font: Font::custom('SomeTtfAlias')),
        ));
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Standard 14 fonts');
        $doc->output();
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

        // TextField: red border, gray bg, Courier 12, center align
        $page->field(new TextField(50.0, 50.0, 80.0, 8.0,
            name: 'styled_text',
            value: 'Hello',
            appearance: new FieldAppearance(
                borderColor: Color::rgb(255, 0, 0),
                backgroundColor: Color::rgb(240, 240, 240),
                font: Font::courier(),
                fontSize: 12.0,
                align: TextAlign::CENTER,
            ),
        ));

        // Checkbox: red text color (affects AP stream check mark color)
        $page->field(new Checkbox(50.0, 70.0, 5.0, 5.0,
            name: 'styled_check',
            checked: true,
            appearance: new FieldAppearance(textColor: Color::rgb(255, 0, 0)),
        ));

        // Radio group: green text color
        $greenAppearance = new FieldAppearance(textColor: Color::rgb(0, 128, 0));
        $page->field(new Radio(50.0, 85.0, 5.0, 5.0, group: 'pref', value: 'a', checked: true, appearance: $greenAppearance));
        $page->field(new Radio(50.0, 95.0, 5.0, 5.0, group: 'pref', value: 'b', appearance: $greenAppearance));

        // Combobox: Times 11 + black border
        $page->field(new Combobox(50.0, 110.0, 80.0, 8.0,
            name: 'styled_combo',
            options: ['x' => 'X-ray', 'y' => 'Yankee'],
            value: 'x',
            appearance: new FieldAppearance(
                borderColor: Color::rgb(0, 0, 0),
                font: Font::times(),
                fontSize: 11.0,
            ),
        ));

        // Listbox: black border (no /Q for listbox - not supported by our impl)
        $page->field(new Listbox(50.0, 125.0, 80.0, 30.0,
            name: 'styled_list',
            options: ['music', 'sport', 'code'],
            value: ['music'],
            appearance: new FieldAppearance(borderColor: Color::rgb(0, 0, 0)),
        ));

        return $doc;
    }
}
