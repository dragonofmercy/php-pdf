<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\ButtonAction;
use DragonOfMercy\PhpPdf\Form\PushButton;
use DragonOfMercy\PhpPdf\Form\SubmitFormat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class FormSubmitTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/form/submit.pdf';

    public function testFormSubmitMatchesFixtureBytes(): void
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

    public function testSubmitActionIsPresent(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/S /SubmitForm', $bytes);
        self::assertStringContainsString('/FS /URL', $bytes);
        self::assertStringContainsString('/Flags 4', $bytes);
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
            name: 'send', caption: 'Submit',
            action: ButtonAction::submit('https://example.com/post', SubmitFormat::HTML),
        ));

        return $doc;
    }
}
