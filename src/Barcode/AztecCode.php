<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};
use DragonOfMercy\PhpPdf\Barcode\Aztec\{Encoder, Matrix};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Aztec Code (ISO/IEC 24778), covering Compact (1-4 layers) and Full Range (1-32 layers).
 *
 * The encoder picks the smallest variant + layer count that fits the data at the chosen
 * error-correction level. Default EC is MEDIUM (~23%, the ISO recommended minimum).
 *
 * Aztec is square by construction; pass `w` only (h derived) or `w` and `h` equal.
 * A 2-module quiet zone per side is included in the bounding box.
 *
 * Use {@see self::of()} to construct.
 */
final readonly class AztecCode implements Barcode
{
    private function __construct(
        public string $data,
        public AztecEc $errorCorrection,
        public Color $color,
    ) {}

    /**
     * Create an Aztec Code for the given data.
     *
     * @param string  $data Non-empty payload (any byte sequence; UTF-8 is auto-handled).
     * @param AztecEc $ec   Error correction preset; defaults to MEDIUM (~23%).
     *
     * @throws PdfException If $data is empty.
     */
    public static function of(string $data, AztecEc $ec = AztecEc::MEDIUM): self
    {
        if ($data === '') {
            throw new PdfException('Aztec code data must not be empty');
        }
        return new self($data, $ec, Color::rgb(0, 0, 0));
    }

    /** Returns a copy with the given error correction preset. */
    public function withErrorCorrection(AztecEc $ec): self
    {
        return new self($this->data, $ec, $this->color);
    }

    /** Returns a copy with the given foreground color. */
    public function withColor(Color $color): self
    {
        return new self($this->data, $this->errorCorrection, $color);
    }

    public function encode(): EncodedBarcode
    {
        // Replicate the matrix-building pipeline from draw() so encode() can be
        // called standalone.
        $result = Encoder::encode($this->data, $this->errorCorrection);
        $symbolSize = $result->size();

        $matrix = Matrix::buildBullseye($result->compact, $symbolSize);
        $matrix->placeModeMessage($result->layers, count($result->dataCodewords), $result->compact);
        $matrix->placeData(
            array_merge($result->dataCodewords, $result->ecCodewords),
            $result->codewordBits,
            $result->layers,
            $result->compact,
        );
        if (!$result->compact) {
            $baseMatrixSize = 14 + $result->layers * 4;
            $matrix->placeReferenceGrid($result->compact, $baseMatrixSize);
        }

        /** @var list<list<bool>> $matrixList */
        $matrixList = array_values(array_map('array_values', $matrix->modules));

        return new EncodedBarcode(
            kind: BarcodeKind::MATRIX_2D,
            modules: self::padMatrix($matrixList, 2),
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
     * Renders the Aztec code onto the page.
     *
     * A 2-module quiet zone per side is included within `$w` (and `$h` when provided).
     * `$h` must be null or equal to `$w` (Aztec is square).
     */
    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        if ($h !== null && abs($h - $w) > 0.0001) {
            throw new PdfException(sprintf(
                'Aztec code must be square: w (%g) != h (%g)',
                $w, $h,
            ));
        }
        if ($w <= 0) {
            throw new PdfException(sprintf(
                'Aztec code width must be positive, got %g',
                $w,
            ));
        }

        // Encode the payload.
        $result = Encoder::encode($this->data, $this->errorCorrection);

        // Symbol size (modules, excluding quiet zone).
        $symbolSize = $result->size();

        // 2-module quiet zone per side = 4 total.
        $totalModules = $symbolSize + 4;

        // Build the matrix in the correct order (per Matrix docblock):
        //   1. bullseye, 2. mode message, 3. data spiral, 4. reference grid (Full Range, last).
        $matrix = Matrix::buildBullseye($result->compact, $symbolSize);
        $matrix->placeModeMessage($result->layers, count($result->dataCodewords), $result->compact);
        $matrix->placeData(
            array_merge($result->dataCodewords, $result->ecCodewords),
            $result->codewordBits,
            $result->layers,
            $result->compact,
        );
        if (!$result->compact) {
            // placeReferenceGrid must come LAST so its writes win on shared cells.
            // baseMatrixSize = size excluding reference grid bands (pre-expansion).
            $baseMatrixSize = 14 + $result->layers * 4;
            $matrix->placeReferenceGrid($result->compact, $baseMatrixSize);
        }

        // Render: convert size to PDF points, add quiet zone offset.
        $unit     = $page->unit;
        $xPt      = $unit->toPoints($x);
        $yPt      = $unit->toPoints($y);
        $sizePt   = $unit->toPoints($w);
        $modulePt = $sizePt / $totalModules;
        $quietPt  = 2 * $modulePt;

        /** @var list<list<bool>> $matrixList */
        $matrixList = array_values(array_map('array_values', $matrix->modules));
        $body = Renderer::runLengthMatrix($matrixList, $xPt + $quietPt, $yPt + $quietPt, $modulePt);
        $page->contentStream()->append(Renderer::wrap($body, $this->color));
    }
}
