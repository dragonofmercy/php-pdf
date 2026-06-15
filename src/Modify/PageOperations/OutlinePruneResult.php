<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Writer\Object\IndirectObject;

/**
 * @internal
 */
final readonly class OutlinePruneResult
{
    /** @param list<IndirectObject> $objects overridden outline objects */
    public function __construct(
        public array $objects,
        public bool $outlinesEmptied,
    ) {}
}
