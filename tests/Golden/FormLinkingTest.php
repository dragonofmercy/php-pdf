<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use PHPUnit\Framework\TestCase;

final class FormLinkingTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/fixtures/form/linking.pdf';

    public function testFormLinkingMatchesFixtureBytes(): void
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

    public function testLinkedStructurePresent(): void
    {
        $bytes = $this->buildDocument()->output();
        self::assertStringContainsString('/Kids', $bytes);
        self::assertStringContainsString('/Parent', $bytes);
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
        $page->field(new TextField(20, 20, 80, 8, name: 'customer', value: 'ACME'));
        $page->field(new TextField(20, 250, 80, 8, name: 'customer'));
        $page->field(new Checkbox(20, 40, 5, 5, name: 'agree', checked: true));
        $page->field(new Checkbox(120, 40, 5, 5, name: 'agree'));
        $page->field(new TextField(20, 60, 80, 8, name: 'note', value: 'standalone'));
        return $doc;
    }
}
