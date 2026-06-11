<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Unit\PdfA;

use DragonOfMercy\PhpPdf\PdfA\PdfALevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PdfALevelTest extends TestCase
{
    /**
     * @return array<string, array{PdfALevel, int, string, ?string, ?int, bool, bool, bool}>
     */
    public static function levels(): array
    {
        // [level, part, headerVersion, xmpConformance, xmpRev, allowsEmbeddedFiles, requiresUnicode, requiresTagging]
        return [
            'A2B' => [PdfALevel::A2B, 2, '1.7', 'B', null, false, false, false],
            'A2U' => [PdfALevel::A2U, 2, '1.7', 'U', null, false, true, false],
            'A2A' => [PdfALevel::A2A, 2, '1.7', 'A', null, false, true, true],
            'A3B' => [PdfALevel::A3B, 3, '1.7', 'B', null, true, false, false],
            'A3U' => [PdfALevel::A3U, 3, '1.7', 'U', null, true, true, false],
            'A3A' => [PdfALevel::A3A, 3, '1.7', 'A', null, true, true, true],
            'A4' => [PdfALevel::A4, 4, '2.0', null, 2020, false, true, false],
            'A4F' => [PdfALevel::A4F, 4, '2.0', null, 2020, true, true, false],
        ];
    }

    #[DataProvider('levels')]
    public function testProfile(
        PdfALevel $level,
        int $part,
        string $headerVersion,
        ?string $xmpConformance,
        ?int $xmpRev,
        bool $allowsEmbeddedFiles,
        bool $requiresUnicode,
        bool $requiresTagging,
    ): void {
        self::assertSame($part, $level->part());
        self::assertSame($headerVersion, $level->headerVersion());
        self::assertSame($xmpConformance, $level->xmpConformance());
        self::assertSame($xmpRev, $level->xmpRev());
        self::assertSame($allowsEmbeddedFiles, $level->allowsEmbeddedFiles());
        self::assertSame($requiresUnicode, $level->requiresUnicode());
        self::assertSame($requiresTagging, $level->requiresTagging());
    }

    public function testEveryCaseIsCovered(): void
    {
        $covered = array_map(static fn (array $row): PdfALevel => $row[0], self::levels());
        self::assertEqualsCanonicalizing(PdfALevel::cases(), $covered);
    }
}
