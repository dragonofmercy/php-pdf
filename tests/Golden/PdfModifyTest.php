<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DateTimeImmutable;
use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use DragonOfMercy\PhpPdf\Unit;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNull;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class PdfModifyTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/assets/modify/contract.pdf';
    private const string FIXTURE = __DIR__ . '/fixtures/modify/amended-contract.pdf';

    /** Deterministic two-page contract used as the modification source. */
    public static function buildContractSourceBytes(): string
    {
        $doc = new Document(Unit::PT);
        $doc->metadata()
            ->title('Contract')
            ->author('Acme Corp')
            ->creationDate(new DateTimeImmutable('2026-01-01T00:00:00+00:00'))
            ->documentId('00112233445566778899aabbccddeeff');

        $page1 = $doc->addPage();
        $page1->setFont(Font::helvetica()->bold(), 16);
        $page1->text(72, 72, 'Service Agreement');
        $page1->setFont(Font::helvetica(), 11);
        $page1->text(72, 110, 'This agreement is entered into by the parties.');

        $page2 = $doc->addPage();
        $page2->setFont(Font::helvetica(), 11);
        $page2->text(72, 72, 'Signatures and appendices follow.');

        return $doc->output();
    }

    /** Opens the committed source and writes an amended incremental revision. */
    public static function buildAmendedContractBytes(): string
    {
        $pdf = PdfEditor::open(self::SOURCE);
        $pdf->setTitle('Amended contract')->setAuthor('Modifier');
        $page = $pdf->appendPage();
        $page->setFont(Font::helvetica(), 12);
        $page->text(72, 72, 'Amendment no. 1: appended page.');
        return $pdf->output();
    }

    public function testAmendedContractMatchesFixtureBytes(): void
    {
        $actual = self::buildAmendedContractBytes();
        $expected = file_get_contents(self::FIXTURE);
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $actual,
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testAmendedContractPreservesOriginalBytes(): void
    {
        $source = file_get_contents(self::SOURCE);
        self::assertIsString($source);
        $actual = self::buildAmendedContractBytes();
        self::assertSame($source, substr($actual, 0, strlen($source)));
    }

    public function testAmendedContractReopensWithThreePagesAndNewTitle(): void
    {
        $reader = PdfReader::fromBytes(self::buildAmendedContractBytes());
        self::assertSame(3, $reader->pageCount());
        $info = $reader->resolve($reader->trailer()->get(Name::of('Info')) ?? PdfNull::instance());
        self::assertInstanceOf(Dictionary::class, $info);
        self::assertSame('Amended contract', self::infoText($info->get(Name::of('Title'))));
        // an untouched field survives the merge
        self::assertSame('Modifier', self::infoText($info->get(Name::of('Author'))));
    }

    public function testAmendedContractPassesQpdfCheck(): void
    {
        Qpdf::assertCheck(self::FIXTURE);
    }

    /** Decode a reopened /Info text-string value (PdfString or UTF-16BE HexString) to UTF-8. */
    private static function infoText(?PdfObject $value): ?string
    {
        if ($value instanceof PdfString) {
            return $value->value();
        }
        if ($value instanceof HexString) {
            $binary = hex2bin($value->hex());
            if ($binary === false) {
                return null;
            }
            if (str_starts_with($binary, "\xFE\xFF")) {
                return mb_convert_encoding(substr($binary, 2), 'UTF-8', 'UTF-16BE');
            }
            return $binary;
        }
        return null;
    }
}
