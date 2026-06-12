<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use PHPUnit\Framework\TestCase;

final class FlattenFieldsTest extends TestCase
{
    /** A form with a text field and a checkbox. */
    public static function formBytes(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new TextField(20.0, 20.0, 80.0, 8.0, name: 'name'));
        $page->field(new Checkbox(20.0, 35.0, 5.0, 5.0, name: 'agree'));
        return $doc->output();
    }

    public function testFillThenFlattenRemovesAcroForm(): void
    {
        $editor = PdfEditor::fromBytes(self::formBytes());
        $editor->setField('name', 'Hello');
        $editor->setField('agree', true);
        $editor->flattenFields();
        $out = $editor->output();

        $reader = PdfReader::fromBytes($out);

        // The catalog no longer carries /AcroForm (all fields flattened).
        self::assertNull($reader->catalog()->get(Name::of('AcroForm')), '/AcroForm should be gone');

        // The page content references a burned XObject (the appearance Do).
        $page = $reader->page(1);
        $content = '';
        foreach ($page->contents as $ref) {
            $stream = $reader->resolve($ref);
            if ($stream instanceof \DragonOfMercy\PhpPdf\Reader\ReadStream) {
                $content .= $reader->decodeStream($stream);
            }
        }
        self::assertStringContainsString('Do', $content, 'burned appearance Do operator expected');
    }
}
