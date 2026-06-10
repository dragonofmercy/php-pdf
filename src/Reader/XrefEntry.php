<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

/**
 * One cross-reference entry. Field meaning depends on the kind:
 * InFile: first = byte offset (relative to the %PDF header), second = generation.
 * InObjectStream: first = object number of the /ObjStm, second = index inside it.
 * Free: both zero.
 *
 * @internal
 */
final readonly class XrefEntry
{
    private function __construct(
        public XrefEntryKind $kind,
        public int $first,
        public int $second,
    ) {}

    public static function inFile(int $offset, int $generation): self
    {
        return new self(XrefEntryKind::InFile, $offset, $generation);
    }

    public static function inObjectStream(int $objStmNumber, int $index): self
    {
        return new self(XrefEntryKind::InObjectStream, $objStmNumber, $index);
    }

    public static function free(): self
    {
        return new self(XrefEntryKind::Free, 0, 0);
    }
}
