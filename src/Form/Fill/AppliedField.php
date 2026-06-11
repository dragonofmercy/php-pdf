<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Form\Fill;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * @internal Result of applying a value to a field: the indirect objects that
 * must be appended to the incremental revision (the re-emitted field/widget
 * object(s) plus any generated appearance Form XObject(s)).
 */
final readonly class AppliedField
{
    /** @param list<IndirectObject> $objects */
    public function __construct(public array $objects) {}
}
