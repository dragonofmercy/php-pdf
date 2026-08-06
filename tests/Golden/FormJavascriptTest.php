<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\Action\Calculate;
use DragonOfMercy\PhpPdf\Form\Action\FieldActions;
use DragonOfMercy\PhpPdf\Form\Action\Format;
use DragonOfMercy\PhpPdf\Form\Action\Validate;
use DragonOfMercy\PhpPdf\Form\ButtonAction;
use DragonOfMercy\PhpPdf\Form\PushButton;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use PHPUnit\Framework\TestCase;

final class FormJavascriptTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/form/javascript.pdf';

    public function testFormJavascriptMatchesFixtureBytes(): void
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

    public function testDocumentScriptIsPresent(): void
    {
        $bytes = $this->buildDocument()->output();
        // The document-level /JavaScript name tree must be present in the /Names dict.
        self::assertStringContainsString('/Names [(init)', $bytes);
        // The JavaScript action must be present.
        self::assertStringContainsString('/S /JavaScript', $bytes);
    }

    public function testFieldActionsAAIsPresent(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/AA', $bytes);
    }

    public function testCalculateOrderCOIsPresent(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/CO', $bytes);
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
        $doc->addDocumentScript('init', 'console.println("ready");');
        $page = $doc->addPage();

        $page->field(new TextField(20.0, 20.0, 40.0, 8.0, name: 'qty',
            actions: FieldActions::new()
                ->format(Format::number(0))
                ->validate(Validate::range(0, 999))));

        $page->field(new TextField(20.0, 32.0, 40.0, 8.0, name: 'price',
            actions: FieldActions::new()->format(Format::currency('EUR', 2))));

        $page->field(new TextField(20.0, 44.0, 40.0, 8.0, name: 'total', readOnly: true,
            actions: FieldActions::new()
                ->calculate(Calculate::product(['qty', 'price']))
                ->format(Format::currency('EUR', 2))));

        $page->field(PushButton::of(20.0, 56.0, 30.0, 10.0, name: 'reset', caption: 'Reset',
            action: ButtonAction::resetForm(),
            actions: FieldActions::new()->onMouseEnter('app.beep(0);')));

        return $doc;
    }
}
