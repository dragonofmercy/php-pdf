<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Signature;

use DragonOfMercy\PhpPdf\Form\Fill\PdfLiteralEscape;

/**
 * Builds the content stream + bbox for a visible signature's /AP /N Form
 * XObject: the caption rendered as Helvetica lines inside the box. Pure: no
 * object allocation (the caller assembles the stream object + a Helvetica
 * font resource under the /Helv alias).
 *
 * @internal
 */
final readonly class SignatureAppearanceBuilder
{
    private const float FONT_SIZE = 9.0;
    private const float LEADING = 11.0;
    private const float PADDING = 2.0;

    /**
     * @return array{content: string, bbox: array{float, float, float, float}}
     */
    public function build(SignatureAppearance $ap): array
    {
        $lines = $ap->caption !== null && $ap->caption !== '' ? explode("\n", $ap->caption) : [];

        $fontSizeStr = self::num(self::FONT_SIZE);
        $paddingStr = self::num(self::PADDING);

        $ops = "/Tx BMC\nq\n";
        $y = $ap->height - self::PADDING - self::FONT_SIZE;
        foreach ($lines as $line) {
            $escaped = PdfLiteralEscape::escape($line);
            $ops .= sprintf(
                "BT /Helv %s Tf 0 g %s %s Td (%s) Tj ET\n",
                $fontSizeStr,
                $paddingStr,
                self::num($y),
                $escaped,
            );
            $y -= self::LEADING;
        }
        $ops .= "Q\nEMC\n";

        return ['content' => $ops, 'bbox' => [0.0, 0.0, $ap->width, $ap->height]];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
    }
}
