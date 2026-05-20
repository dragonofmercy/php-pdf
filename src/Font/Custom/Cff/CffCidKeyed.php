<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

/**
 * CID-keyed payload (Adobe TN #5176 section 20): an FDArray INDEX of Font
 * DICTs, one CffNameKeyedPrivate per FD, and the FDSelect (GID -> FD index)
 * map. $fdSelectFormat (0 = byte array, 3 = range table) is informational;
 * $fdSelectRawBytes carries the original on-disk encoding which the writer
 * re-emits verbatim.
 *
 * @internal
 */
final readonly class CffCidKeyed
{
    /**
     * @param list<array<string, int|float|array<int, int|float>>> $fontDicts      one entry per FD
     * @param list<CffNameKeyedPrivate>                            $fdPrivates     one entry per FD
     * @param array<int, int>                                      $fdSelect       GID -> FD index
     */
    public function __construct(
        public array $fontDicts,
        public array $fdPrivates,
        public array $fdSelect,
        public int $fdSelectFormat,
        public string $fdSelectRawBytes,
    ) {}
}
