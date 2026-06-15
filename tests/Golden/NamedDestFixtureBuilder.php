<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

/**
 * Builds a minimal PDF with a /Names /Dests name tree of two named
 * destinations (dest_p1 -> page 1, dest_p2 -> page 2). Used by the
 * NamedDestinationPruner tests; the library has no named-destination writer.
 */
final class NamedDestFixtureBuilder
{
    public static function build(): string
    {
        $body = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $add = static function (int $num, string $payload) use (&$body, &$offsets): void {
            $offsets[$num] = strlen($body);
            $body .= "{$num} 0 obj\n{$payload}\nendobj\n";
        };

        $add(1, "<< /Type /Catalog /Pages 2 0 R /Names 6 0 R >>");
        $add(2, "<< /Type /Pages /Kids [ 3 0 R 4 0 R ] /Count 2 >>");
        $add(3, "<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 595 842 ] >>");
        $add(4, "<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 595 842 ] >>");
        $add(6, "<< /Dests 7 0 R >>");
        $add(7, "<< /Names [ (dest_p1) [ 3 0 R /Fit ] (dest_p2) [ 4 0 R /Fit ] ] >>");

        $xrefOffset = strlen($body);
        $body .= "xref\n0 8\n";
        $body .= "0000000000 65535 f \n";
        for ($n = 1; $n <= 7; $n++) {
            if ($n === 5) {
                $body .= "0000000000 65535 f \n";
                continue;
            }
            $body .= str_pad((string) $offsets[$n], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $body .= "trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
        return $body;
    }
}
