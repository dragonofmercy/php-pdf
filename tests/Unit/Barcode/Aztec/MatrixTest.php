<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\Barcode\Aztec;

use DragonOfMercy\PhpPdf\Barcode\Aztec\Matrix;
use PHPUnit\Framework\TestCase;

final class MatrixTest extends TestCase
{
    public function testConstructorCreatesSquareMatrixOfFalse(): void
    {
        $m = new Matrix(7);
        self::assertCount(7, $m->modules);
        foreach ($m->modules as $row) {
            self::assertCount(7, $row);
            foreach ($row as $cell) {
                self::assertFalse($cell);
            }
        }
    }

    public function testCompactBullseyeCentreIsDark(): void
    {
        // Compact 1-layer realistic matrix size is 15 (baseMatrixSize = 11 + 4 = 15).
        $m = Matrix::buildBullseye(compact: true, totalSize: 15);
        self::assertTrue($m->modules[7][7]);
    }

    public function testCompactBullseyeRingsAlternate(): void
    {
        $m = Matrix::buildBullseye(compact: true, totalSize: 15);
        // Centre dark, ring 1 light, ring 2 dark, ring 3 light, ring 4 dark.
        // All cells at Chebyshev distance r from centre share the same value.
        // Walk along the horizontal axis from centre to the right edge of the
        // bullseye (radius 4).
        self::assertTrue($m->modules[7][7], 'centre dark');
        self::assertFalse($m->modules[7][8], 'ring 1 light');
        self::assertTrue($m->modules[7][9], 'ring 2 dark');
        self::assertFalse($m->modules[7][10], 'ring 3 light');
        self::assertTrue($m->modules[7][11], 'ring 4 dark');
    }

    public function testCompactBullseyeOuterRingIsFullSquare(): void
    {
        // Ring 4 is the outermost dark bullseye ring; every cell on the
        // perimeter of the 9x9 region should be dark.
        $m = Matrix::buildBullseye(compact: true, totalSize: 15);
        for ($c = 3; $c <= 11; $c++) {
            self::assertTrue($m->modules[3][$c], "top edge col $c dark");
            self::assertTrue($m->modules[11][$c], "bottom edge col $c dark");
            self::assertTrue($m->modules[$c][3], "left edge row $c dark");
            self::assertTrue($m->modules[$c][11], "right edge row $c dark");
        }
    }

    public function testCompactBullseyeLightRingIsHollow(): void
    {
        // Ring 3 is light: cells on the perimeter of the 7x7 region around
        // the centre (rows / cols 4..10) should all be light.
        $m = Matrix::buildBullseye(compact: true, totalSize: 15);
        for ($c = 4; $c <= 10; $c++) {
            self::assertFalse($m->modules[4][$c], "ring 3 top col $c light");
            self::assertFalse($m->modules[10][$c], "ring 3 bottom col $c light");
            self::assertFalse($m->modules[$c][4], "ring 3 left row $c light");
            self::assertFalse($m->modules[$c][10], "ring 3 right row $c light");
        }
    }

    public function testFullBullseyeIs13x13(): void
    {
        // Full Range 1-layer matrix size = baseMatrixSize + 1 = 15 (no grid yet).
        // Centre at 7. Bullseye radius 6 -> 13x13 region at rows/cols 1..13.
        $m = Matrix::buildBullseye(compact: false, totalSize: 15);
        self::assertTrue($m->modules[7][7], 'centre dark');
        // Walk to the right edge: ring 1 light, 2 dark, 3 light, 4 dark, 5 light, 6 dark.
        self::assertFalse($m->modules[7][8]);
        self::assertTrue($m->modules[7][9]);
        self::assertFalse($m->modules[7][10]);
        self::assertTrue($m->modules[7][11]);
        self::assertFalse($m->modules[7][12]);
        self::assertTrue($m->modules[7][13], 'outer ring dark');
    }

    public function testFullBullseyeOuterRingIsFullSquare(): void
    {
        $m = Matrix::buildBullseye(compact: false, totalSize: 15);
        for ($c = 1; $c <= 13; $c++) {
            self::assertTrue($m->modules[1][$c]);
            self::assertTrue($m->modules[13][$c]);
            self::assertTrue($m->modules[$c][1]);
            self::assertTrue($m->modules[$c][13]);
        }
    }

    public function testCompactOrientationMarks(): void
    {
        // zxing-java Encoder.drawBullsEye with size = 5 sets six cells outside
        // the bullseye proper. With totalSize = 15, centre = 7, the corner
        // marks land at the matrix corners (rows/cols 2 and 12):
        //   (centre-5, centre-5),     (centre-5+1, centre-5),     (centre-5, centre-5+1)
        //   (centre+5, centre-5),     (centre+5, centre-5+1),     (centre+5, centre+5-1)
        // Coordinates in zxing are (col, row); modules here are [row][col].
        $m = Matrix::buildBullseye(compact: true, totalSize: 15);
        self::assertTrue($m->modules[2][2],   'top-left corner');
        self::assertTrue($m->modules[2][3],   'top-left + right');
        self::assertTrue($m->modules[3][2],   'top-left + below');
        self::assertTrue($m->modules[2][12],  'top-right corner');
        self::assertTrue($m->modules[3][12],  'top-right + below');
        self::assertTrue($m->modules[11][12], 'bottom-right + above');
    }

    public function testFullOrientationMarks(): void
    {
        // Full Range with size = 7: corner marks at (centre +/- 7, centre +/- 7).
        $m = Matrix::buildBullseye(compact: false, totalSize: 15);
        self::assertTrue($m->modules[0][0]);
        self::assertTrue($m->modules[0][1]);
        self::assertTrue($m->modules[1][0]);
        self::assertTrue($m->modules[0][14]);
        self::assertTrue($m->modules[1][14]);
        self::assertTrue($m->modules[13][14]);
    }

    public function testMatrixHasRequestedSize(): void
    {
        $m = Matrix::buildBullseye(compact: true, totalSize: 15);
        self::assertCount(15, $m->modules);
        foreach ($m->modules as $row) {
            self::assertCount(15, $row);
        }
    }

    public function testCellsOutsideBullseyeRemainLight(): void
    {
        // For Compact with totalSize = 15 and bullseye radius 4 centered at 7,
        // the bullseye spans rows / cols 3..11. Corner marks land at rows /
        // cols 2 and 12. Cells outside that footprint (e.g. row 0, col 0)
        // should still be light.
        $m = Matrix::buildBullseye(compact: true, totalSize: 15);
        self::assertFalse($m->modules[0][0]);
        self::assertFalse($m->modules[0][14]);
        self::assertFalse($m->modules[14][0]);
        self::assertFalse($m->modules[14][14]);
        self::assertFalse($m->modules[7][0]);
        self::assertFalse($m->modules[0][7]);
    }

    public function testPlaceReferenceGridIsNoOpForCompact(): void
    {
        // Compact symbols have no reference grid: calling the method must
        // not change a single module compared to the bullseye-only snapshot.
        $m        = Matrix::buildBullseye(compact: true, totalSize: 15);
        $snapshot = $m->modules;
        $m->placeReferenceGrid(compact: true, baseMatrixSize: 15);
        self::assertSame($snapshot, $m->modules);
    }

    public function testPlaceReferenceGridDegenerateJZeroPassPaintsCentreLines(): void
    {
        // Full Range layer 1: baseMatrixSize = 14 + 4 = 18 -> matrixSize = 19.
        // halfBase = 8, so the outer loop runs exactly once at i = 0, j = 0.
        // The j = 0 pass writes the centre row and centre column with the
        // centre-parity stride. Most of these cells are already dark from
        // the bullseye, but the cells at the matrix edge (centre +/- 9 from
        // centre, i.e. cols 0 and 18 at row 9, and rows 0 and 18 at col 9)
        // sit OUTSIDE the bullseye footprint and become dark.
        //
        // Layer-1 Full Range is not produced by the standard zxing size
        // search (which jumps to Compact 4 then Full 4); we still match
        // zxing's algorithm exactly so callers using `userSpecifiedLayers`
        // get byte-identical output.
        $m = Matrix::buildBullseye(compact: false, totalSize: 19);
        $m->placeReferenceGrid(compact: false, baseMatrixSize: 18);

        // Parity at centre 9 is odd, so odd columns on row 9 become dark.
        // Cells at the bullseye corners (cols 1 and 17) were not painted by
        // the bullseye and now flip on.
        self::assertTrue($m->modules[9][1],  'centre row, col 1 painted by grid');
        self::assertTrue($m->modules[9][17], 'centre row, col 17 painted by grid');
        self::assertTrue($m->modules[1][9],  'centre col, row 1 painted by grid');
        self::assertTrue($m->modules[17][9], 'centre col, row 17 painted by grid');
        // Cells of the opposite parity on the centre row remain light.
        self::assertFalse($m->modules[9][0],  'even col, untouched');
        self::assertFalse($m->modules[9][18], 'even col, untouched');
    }

    public function testPlaceReferenceGridPlacesGridBandsForLargeFullRange(): void
    {
        // Full Range layer 5: baseMatrixSize = 14 + 5*4 = 34.
        //   matrixSize = 34 + 1 + 2 * ((34/2 - 1) / 15) = 34 + 1 + 2 = 37.
        //   centre     = 18, parity = 18 & 1 = 0.
        //   halfBase   = 34/2 - 1 = 16, so the outer loop runs i = 0 (j = 0)
        //                                            and i = 15 (j = 16).
        // The second iteration draws bands at columns 2 / 34 and rows 2 / 34,
        // setting every even-index cell along the band.
        $m = Matrix::buildBullseye(compact: false, totalSize: 37);
        $m->placeReferenceGrid(compact: false, baseMatrixSize: 34);

        // Spot check the four grid lines at j = 16: every k of matching
        // parity (here even k) becomes dark.
        self::assertTrue($m->modules[0][2],   'top band, leftmost even col');
        self::assertTrue($m->modules[36][2],  'top band, rightmost even col');
        self::assertTrue($m->modules[0][34],  'bottom-side vertical band');
        self::assertTrue($m->modules[36][34], 'bottom-side vertical band, bottom row');
        self::assertTrue($m->modules[2][0],   'left horizontal band, top edge');
        self::assertTrue($m->modules[2][36],  'left horizontal band, right edge');
        self::assertTrue($m->modules[34][0],  'right horizontal band');
        self::assertTrue($m->modules[34][36], 'right horizontal band, far end');

        // Cells of the OPPOSITE parity on a band stay light: at row 2, an
        // odd column outside the bullseye must remain false.
        self::assertFalse($m->modules[2][1],  'band row, odd col stays light');
        self::assertFalse($m->modules[2][35], 'band row, odd col stays light');
        self::assertFalse($m->modules[1][2],  'band col, odd row stays light');
        self::assertFalse($m->modules[35][2], 'band col, odd row stays light');
    }

    public function testPlaceReferenceGridAlternatesAlongTheBand(): void
    {
        // Same layer-5 matrix as above. Walking column 2 from top to bottom,
        // even rows are dark, odd rows are light (until we cross the
        // bullseye, which is itself dark and overrules the parity check).
        $m = Matrix::buildBullseye(compact: false, totalSize: 37);
        $m->placeReferenceGrid(compact: false, baseMatrixSize: 34);

        // Outside the bullseye footprint (bullseye spans rows 11..25 for
        // centre 18 radius 7 -> corner marks 11..25 too), check alternation
        // along column 2.
        for ($row = 0; $row <= 10; $row++) {
            $expected = ($row % 2) === 0;
            self::assertSame($expected, $m->modules[$row][2], "col 2 row $row");
        }
        for ($row = 26; $row <= 36; $row++) {
            $expected = ($row % 2) === 0;
            self::assertSame($expected, $m->modules[$row][2], "col 2 row $row");
        }
    }
}
