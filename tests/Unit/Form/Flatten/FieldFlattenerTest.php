<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Form\Flatten;

use DragonOfMercy\PhpPdf\Document;
use DragonOfMercy\PhpPdf\Font\MetricsRegistry;
use DragonOfMercy\PhpPdf\Form\Checkbox;
use DragonOfMercy\PhpPdf\Form\Fill\FieldTree;
use DragonOfMercy\PhpPdf\Form\Fill\FieldValueApplier;
use DragonOfMercy\PhpPdf\Form\Fill\FormFieldType;
use DragonOfMercy\PhpPdf\Form\Flatten\FieldFlattener;
use DragonOfMercy\PhpPdf\Form\Flatten\FlattenTarget;
use DragonOfMercy\PhpPdf\Form\TextField;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Writer\Object\CompressedStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfArray;
use DragonOfMercy\PhpPdf\Writer\Object\PdfReference;
use PHPUnit\Framework\TestCase;

final class FieldFlattenerTest extends TestCase
{
    /** Builds a 1-page form with a checkbox (which carries an /AP /N out of the box). */
    private static function checkboxFormBytes(): string
    {
        $doc = new Document();
        $page = $doc->addPage();
        $page->field(new Checkbox(20.0, 20.0, 5.0, 5.0, name: 'agree'));
        return $doc->output();
    }

    public function testFlattenCheckboxRemovesWidgetAndBurnsAppearance(): void
    {
        $reader = PdfReader::fromBytes(self::checkboxFormBytes());
        $tree = new FieldTree($reader);
        $applier = new FieldValueApplier($reader, new MetricsRegistry());

        $fields = $tree->terminalFields();
        self::assertCount(1, $fields);
        $field = $fields[0];
        self::assertSame(FormFieldType::Checkbox, $field->type);

        // Final value true -> burn the On appearance (existing /AP, regenerate=false).
        $target = new FlattenTarget($field, true, false);

        $next = $reader->maxObjectNumber() + 1;
        $allocate = function () use (&$next): int { return $next++; };

        $flattener = new FieldFlattener($reader, $applier);
        $result = $flattener->flatten([$target], $allocate);

        // The field object is reported for removal from /AcroForm /Fields.
        self::assertSame([$field->objectNumber], $result->removedFieldObjectNumbers);

        // A re-emitted page object is present whose /Annots no longer lists the widget.
        $pageObj = self::findPage($result->objects);
        self::assertNotNull($pageObj, 'a re-emitted page object is expected');
        $annots = $pageObj->dictionaryPayload()->get(Name::of('Annots'));
        if ($annots instanceof PdfArray) {
            foreach ($annots->elements() as $el) {
                self::assertFalse(
                    $el instanceof PdfReference && $el->objectNumber === $field->objectNumber,
                    'flattened widget must be gone from /Annots',
                );
            }
        }

        // A burned content stream referencing an XObject with a Do operator exists.
        $hasBurn = false;
        foreach ($result->objects as $o) {
            $payload = $o->payload();
            if ($payload instanceof CompressedStream && str_contains($payload->rawContentForTest(), ' Do')) {
                $hasBurn = true;
            }
        }
        self::assertTrue($hasBurn, 'a content stream with a Do operator is expected');

        // The re-emitted page /Resources /XObject registers the burned appearance.
        $resources = $pageObj->dictionaryPayload()->get(Name::of('Resources'));
        self::assertInstanceOf(Dictionary::class, $resources);
        self::assertInstanceOf(Dictionary::class, $resources->get(Name::of('XObject')));
    }

    /** @param list<\DragonOfMercy\PhpPdf\Writer\Object\IndirectObject> $objects */
    private static function findPage(array $objects): ?\DragonOfMercy\PhpPdf\Writer\Object\IndirectObject
    {
        foreach ($objects as $o) {
            $payload = $o->payload();
            if ($payload instanceof Dictionary) {
                $type = $payload->get(Name::of('Type'));
                if ($type instanceof Name && $type->value() === 'Page') {
                    return $o;
                }
            }
        }
        return null;
    }
}
