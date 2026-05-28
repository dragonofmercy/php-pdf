<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};
use DragonOfMercy\PhpPdf\Barcode\DataMatrix\{Encoder, Matrix};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * DataMatrix barcode (ISO/IEC 16022, ECC200), square symbols 10x10 to 144x144.
 *
 * The encoder picks the smallest square that fits the encoded data plus the
 * symbol's fixed Reed-Solomon overhead. There is no public EC level: the EC
 * ratio is dictated by the chosen symbol size per ISO 16022 Table 7.
 *
 * DataMatrix is square by construction; pass `w` only (h derived) or `w` and
 * `h` equal. A 1-module quiet zone per side is included in the bounding box.
 *
 * Use {@see self::of()} to construct.
 */
final readonly class DataMatrix implements Barcode
{
    private function __construct(
        public string $data,
        public Color  $color,
    ) {}

    /**
     * Create a DataMatrix barcode for the given payload.
     *
     * @param string $data Non-empty payload (any byte sequence; UTF-8 auto-encoded via Base256).
     *
     * @throws PdfException If $data is empty.
     */
    public static function of(string $data): self
    {
        if ($data === '') {
            throw new PdfException('DataMatrix data must not be empty');
        }
        return new self($data, Color::rgb(0, 0, 0));
    }

    /** Returns a copy with the given foreground color. */
    public function withColor(Color $color): self
    {
        return new self($this->data, $color);
    }

    public function encode(): EncodedBarcode
    {
        // Replicate the matrix-building pipeline from draw() so encode() can be
        // called standalone.
        $result = Encoder::encode($this->data);
        $matrix = Matrix::build($result->symbol);
        $matrix->placeCodewords($result->finalCodewords);

        /** @var list<list<bool>> $matrixList */
        $matrixList = array_values(array_map('array_values', $matrix->modules));

        return new EncodedBarcode(
            kind: BarcodeKind::MATRIX_2D,
            modules: self::padMatrix($matrixList, 1),
            humanTextSegments: [],
            color: $this->color,
            orientation: Orientation::Horizontal,
        );
    }

    /**
     * Symmetrically pads a 2D matrix with `false` modules on every side.
     *
     * @param list<list<bool>> $matrix
     * @return list<list<bool>>
     */
    private static function padMatrix(array $matrix, int $quiet): array
    {
        $size = count($matrix);
        $width = $size + 2 * $quiet;
        $emptyRow = array_fill(0, $width, false);
        $result = [];
        for ($i = 0; $i < $quiet; $i++) {
            $result[] = $emptyRow;
        }
        foreach ($matrix as $row) {
            $padded = array_merge(
                array_fill(0, $quiet, false),
                $row,
                array_fill(0, $quiet, false),
            );
            $result[] = $padded;
        }
        for ($i = 0; $i < $quiet; $i++) {
            $result[] = $emptyRow;
        }
        return $result;
    }

    /**
     * Renders the DataMatrix onto the page.
     *
     * A 1-module quiet zone per side is included within `$w` (and `$h` when provided).
     * `$h` must be null or equal to `$w` (DataMatrix is square in this build).
     */
    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        if ($h !== null && abs($h - $w) > 0.0001) {
            throw new PdfException(sprintf(
                'DataMatrix must be square: w (%g) != h (%g)',
                $w, $h,
            ));
        }
        if ($w <= 0) {
            throw new PdfException(sprintf(
                'DataMatrix width must be positive, got %g',
                $w,
            ));
        }

        $result = Encoder::encode($this->data);
        $matrix = Matrix::build($result->symbol);
        $matrix->placeCodewords($result->finalCodewords);

        $symbolModules = $result->symbol->moduleRows;
        $totalModules  = $symbolModules + 2; // 1-module quiet zone per side

        $unit     = $page->unit;
        $xPt      = $unit->toPoints($x);
        $yPt      = $unit->toPoints($y);
        $sizePt   = $unit->toPoints($w);
        $modulePt = $sizePt / $totalModules;
        $quietPt  = $modulePt;

        /** @var list<list<bool>> $matrixList */
        $matrixList = array_values(array_map('array_values', $matrix->modules));
        $body = Renderer::runLengthMatrix($matrixList, $xPt + $quietPt, $yPt + $quietPt, $modulePt);
        $page->contentStream()->append(Renderer::wrap($body, $this->color));
    }
}
