<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Page;

use DragonOfMercy\PhpPdf\ColumnFill;
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Equal-width column geometry for a Page::columns() block, in points. Pure
 * horizontal layout: column widths are constant, so flowing across a column
 * boundary only shifts the x origin by $stepPt.
 *
 * @internal
 */
final readonly class ColumnLayout
{
    private function __construct(
        public int $count,
        public float $gapPt,
        public float $leftPt,
        public float $topPt,
        public float $widthPt,
        public float $stepPt,
        public ColumnFill $fill,
    ) {}

    public static function compute(
        int $count,
        float $gapPt,
        float $leftPt,
        float $topPt,
        float $contentWidthPt,
        ColumnFill $fill,
    ): self {
        if ($count < 1) {
            throw new PdfException('column count must be at least 1, got ' . $count);
        }
        if ($gapPt < 0.0) {
            throw new PdfException('column gap cannot be negative, got ' . $gapPt);
        }
        $widthPt = ($contentWidthPt - ($count - 1) * $gapPt) / $count;
        if ($widthPt <= 0.0) {
            throw new PdfException(
                'columns do not fit: ' . $count . ' columns with gap ' . $gapPt
                . ' exceed the content width ' . $contentWidthPt,
            );
        }
        return new self($count, $gapPt, $leftPt, $topPt, $widthPt, $widthPt + $gapPt, $fill);
    }

    public function leftPtForColumn(int $index): float
    {
        return $this->leftPt + $index * $this->stepPt;
    }
}
