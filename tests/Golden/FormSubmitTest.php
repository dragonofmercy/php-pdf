<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\ButtonAction;
use DragonOfMercy\PhpPdf\Form\PushButton;
use DragonOfMercy\PhpPdf\Form\SubmitFormat;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use PHPUnit\Framework\TestCase;

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
        Qpdf::assertCheck(self::FIXTURE);
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
