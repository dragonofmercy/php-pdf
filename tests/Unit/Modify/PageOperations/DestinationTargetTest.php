<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Modify\PageOperations\DestinationTarget;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfNumber;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\TestCase;

final class DestinationTargetTest extends TestCase
{
    private function reader(): PdfReader
    {
        $doc = new \DragonOfMercy\PhpPdf\Document();
        $doc->addPage();
        return PdfReader::fromBytes($doc->output());
    }

    public function testExplicitArrayDestination(): void
    {
        $dest = PdfArray::of(PdfReference::to(7, 0), Name::of('XYZ'), PdfNumber::ofInt(0), PdfNumber::ofInt(792), PdfNumber::ofInt(0));
        self::assertSame(7, DestinationTarget::pageObjectNumber($dest, $this->reader()));
    }

    public function testGoToActionDictionary(): void
    {
        $action = Dictionary::empty()
            ->withEntry(Name::of('S'), Name::of('GoTo'))
            ->withEntry(Name::of('D'), PdfArray::of(PdfReference::to(9, 0), Name::of('Fit')));
        self::assertSame(9, DestinationTarget::pageObjectNumber($action, $this->reader()));
    }

    public function testNamedDestinationFormReturnsNull(): void
    {
        $action = Dictionary::empty()
            ->withEntry(Name::of('S'), Name::of('GoTo'))
            ->withEntry(Name::of('D'), PdfString::of('chapter3'));
        self::assertNull(DestinationTarget::pageObjectNumber($action, $this->reader()));
    }

    public function testIntegerPageNumberFormReturnsNull(): void
    {
        $dest = PdfArray::of(PdfNumber::ofInt(2), Name::of('Fit'));
        self::assertNull(DestinationTarget::pageObjectNumber($dest, $this->reader()));
    }

    public function testUriActionReturnsNull(): void
    {
        $action = Dictionary::empty()
            ->withEntry(Name::of('S'), Name::of('URI'))
            ->withEntry(Name::of('URI'), PdfString::of('https://example.com'));
        self::assertNull(DestinationTarget::pageObjectNumber($action, $this->reader()));
    }
}
