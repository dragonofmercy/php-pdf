<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Modify\PageOperations;

use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;

/**
 * A surviving source page: its object number, raw leaf dict, and the inherited
 * attributes the reader already resolved (so the rewriter never re-derives
 * inheritance when flattening).
 *
 * @internal
 */
final readonly class PageRecord
{
    /**
     * @param list<float> $mediaBox
     * @param ?list<float> $cropBox
     */
    public function __construct(
        public int $objectNumber,
        public Dictionary $dict,
        public array $mediaBox,
        public ?array $cropBox,
        public int $rotate,
        public ?Dictionary $resources,
    ) {}
}
