<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

/**
 * Structured payload that the offsets in a Top DICT point at. Either
 * name-keyed (namePrivate set, cidKeyed null) or CID-keyed (cidKeyed set,
 * namePrivate null). Discrimination at read time = presence of the ROS
 * operator in the Top DICT.
 *
 * @internal
 */
final readonly class CffTopDictData
{
    public function __construct(
        public CffCharset $charset,
        public ?CffEncoding $encoding,
        public CffCharStrings $charStrings,
        public ?CffNameKeyedPrivate $namePrivate,
        public ?CffCidKeyed $cidKeyed,
    ) {}
}
