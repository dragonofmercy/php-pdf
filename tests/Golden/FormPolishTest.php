<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Color;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\FieldAppearance;
use DragonOfMercy\PhpPdf\Form\FieldBorderStyle;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\TabOrder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class FormPolishTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/form-polish.pdf';

    public function testFormPolishMatchesFixtureBytes(): void
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

    public function testPolishFeaturesPresent(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/BS << /W', $bytes);
        self::assertStringContainsString('/Tabs /R', $bytes);
        self::assertStringContainsString('/F 2', $bytes);
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
        $page->setTabOrder(TabOrder::ROW);

        // Beveled border + NoExport + decoupled defaultValue.
        $page->field(new TextField(20, 20, 80, 8, name: 'styled', value: 'now', defaultValue: 'orig',
            appearance: new FieldAppearance(
                borderColor: Color::rgb(0, 0, 0),
                borderWidth: 1.0,
                borderStyle: FieldBorderStyle::BEVELED,
                noExport: true,
            )));

        // Hidden field.
        $page->field(new TextField(20, 40, 80, 8, name: 'secret',
            appearance: new FieldAppearance(hidden: true)));

        // Checkbox with decoupled default (checked now, default off).
        $page->field(new Checkbox(20, 60, 5, 5, name: 'agree', checked: true, defaultValue: false));

        return $doc;
    }
}
