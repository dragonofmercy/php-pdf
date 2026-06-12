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
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

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

    public function testFlattenSubsetKeepsOtherFieldInteractive(): void
    {
        $editor = PdfEditor::fromBytes(self::formBytes());
        $editor->setField('name', 'Hello');
        $editor->setField('agree', true);
        $editor->flattenFields(['name']); // flatten only the text field
        $out = $editor->output();

        $reader = PdfReader::fromBytes($out);

        // /AcroForm survives with the checkbox still present.
        $acro = $reader->catalog()->get(Name::of('AcroForm'));
        self::assertNotNull($acro, '/AcroForm must remain for the un-flattened field');

        // The remaining field is the checkbox.
        $editor2 = PdfEditor::fromBytes($out);
        $names = array_map(static fn ($f) => $f->name, $editor2->formFields());
        self::assertContains('agree', $names);
        self::assertNotContains('name', $names, 'flattened field must be gone');
    }

    public function testNeedAppearancesFalseOnPartialFlatten(): void
    {
        $editor = PdfEditor::fromBytes(self::formBytes());
        $editor->flattenFields(['name']);
        $out = $editor->output();
        self::assertStringContainsString('/NeedAppearances false', $out);
    }

    /** A form with a text field plus an (unsigned) signature field. */
    public static function formWithSignatureBytes(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new TextField(20.0, 20.0, 80.0, 8.0, name: 'name'));
        $page->field(\DragonOfMercy\PhpPdf\Form\SignatureField::visible(20.0, 40.0, 60.0, 20.0, 'sig1'));
        return $doc->output();
    }

    public function testFlattenAllPreservesSignatureField(): void
    {
        $editor = PdfEditor::fromBytes(self::formWithSignatureBytes());
        $editor->setField('name', 'Hello');
        $editor->flattenFields();            // flatten-all
        $out = $editor->output();

        // /AcroForm must survive because the signature field is not flattened.
        $reader = PdfReader::fromBytes($out);
        self::assertNotNull($reader->catalog()->get(Name::of('AcroForm')), '/AcroForm must remain for the signature field');

        $editor2 = PdfEditor::fromBytes($out);
        $names = array_map(static fn ($f) => $f->name, $editor2->formFields());
        self::assertContains('sig1', $names, 'signature field must be preserved');
        self::assertNotContains('name', $names, 'text field must be flattened away');
    }

    public function testNamingASignatureFieldForFlattenIsRejected(): void
    {
        $editor = PdfEditor::fromBytes(self::formWithSignatureBytes());
        $this->expectException(\DragonOfMercy\PhpPdf\Exception\PdfException::class);
        $this->expectExceptionMessageMatches('/Signature.*cannot be flattened/');
        $editor->flattenFields(['sig1']);
    }

    public function testPikepdfSeesNoAcroFormAfterFlattenAll(): void
    {
        $python = (new ExecutableFinder())->find('python') ?? (new ExecutableFinder())->find('python3');
        if ($python === null) {
            self::markTestSkipped('python not on PATH; cross-tool pikepdf anchor skipped');
        }
        if (!is_dir('C:/tmp/pyenc')) {
            self::markTestSkipped('pikepdf install absent at C:/tmp/pyenc; cross-tool anchor skipped');
        }

        $editor = PdfEditor::fromBytes(self::formBytes());
        $editor->setField('name', 'Hello');
        $editor->setField('agree', true);
        $editor->flattenFields();
        $out = $editor->output();

        $tmpPdf = (string) tempnam(sys_get_temp_dir(), 'phppdf_flat_');
        $tmpScript = (string) tempnam(sys_get_temp_dir(), 'phppdf_flatpy_');
        try {
            file_put_contents($tmpPdf, $out);
            file_put_contents($tmpScript, <<<'PY'
                import sys
                sys.path.insert(0, 'C:/tmp/pyenc')
                import pikepdf
                with pikepdf.open(sys.argv[1]) as pdf:
                    root = pdf.Root
                    has_form = '/AcroForm' in root and len(getattr(root.AcroForm, 'Fields', [])) > 0
                    print('FORM=' + ('yes' if has_form else 'no'))
                    page = pdf.pages[0]
                    annots = page.get('/Annots', [])
                    print('ANNOTS=' + str(len(annots)))
                PY);

            $process = new Process([$python, $tmpScript, $tmpPdf]);
            $process->run();
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            if (str_contains($stderr, 'ModuleNotFoundError') || str_contains($stderr, 'ImportError')) {
                self::markTestSkipped('pikepdf module not importable; cross-tool anchor skipped: ' . trim($stderr));
            }

            self::assertSame(0, $process->getExitCode(), "pikepdf failed. stdout=[{$stdout}] stderr=[{$stderr}]");
            self::assertStringContainsString('FORM=no', $stdout, "pikepdf should see no interactive form. stdout=[{$stdout}]");
            self::assertStringContainsString('ANNOTS=0', $stdout, "pikepdf should see no widget annotations. stdout=[{$stdout}]");
        } finally {
            @unlink($tmpPdf);
            @unlink($tmpScript);
        }
    }
}
