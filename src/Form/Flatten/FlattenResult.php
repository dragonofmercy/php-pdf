<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Flatten;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * Output of {@see FieldFlattener::flatten()}: the indirect objects to append to
 * the revision (re-emitted pages, burned content streams, generated appearance
 * XObjects) and the object numbers of the flattened fields, which the caller
 * removes from /AcroForm /Fields.
 *
 * @internal
 */
final readonly class FlattenResult
{
    /**
     * @param list<IndirectObject> $objects
     * @param list<int> $removedFieldObjectNumbers
     */
    public function __construct(
        public array $objects,
        public array $removedFieldObjectNumbers,
    ) {}
}
