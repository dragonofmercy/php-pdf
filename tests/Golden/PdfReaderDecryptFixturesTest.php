<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real cross-tool decryption validation. Each fixture is enc-src.pdf (a
 * one-page library document, /Info /Title 'Confidential marker', page text
 * 'SECRET-XYZ-123') re-saved by pikepdf 10.x - a fully independent encryption
 * implementation - under a different scheme/revision:
 *
 *   rc4-40-empty   R2  RC4 40-bit   empty user password
 *   rc4-128-empty  R3  RC4 128-bit  empty user password
 *   rc4-128-pw     R3  RC4 128-bit  user password 'test'
 *   aes-128-empty  R4  AES-128      empty user password
 *   aes-128-pw     R4  AES-128      user password 'test'
 *   aes-256-pw     R6  AES-256      user password 'test'
 *
 * Decrypting pikepdf's output proves our RC4-40, RC4-128, AES-128 and AES-256
 * readers against a different producer (the strongest anchor short of Acrobat).
 * Until this, every encryption test was library-writer -> library-reader.
 *
 * RC4 fixtures keep the XMP metadata stream unencrypted (pikepdf forbids
 * encrypting it for R<4); the /Info dictionary string and the page content
 * stream - what we assert on - are still RC4-encrypted.
 *
 * The fixtures are committed under tests/Golden/assets/encrypted/; a missing
 * file is skipped so the suite stays green in environments where they were not
 * regenerated.
 */
final class PdfReaderDecryptFixturesTest extends TestCase
{
    private const string DIR = __DIR__ . '/assets/encrypted/';

    /** @return iterable<string, array{string, string}> file => password */
    public static function fixtures(): iterable
    {
        yield 'rc4-40-empty'  => ['rc4-40-empty.pdf', ''];
        yield 'rc4-128-empty' => ['rc4-128-empty.pdf', ''];
        yield 'rc4-128-pw'    => ['rc4-128-pw.pdf', 'test'];
        yield 'aes-128-empty' => ['aes-128-empty.pdf', ''];
        yield 'aes-128-pw'    => ['aes-128-pw.pdf', 'test'];
        yield 'aes-256-pw'    => ['aes-256-pw.pdf', 'test'];
    }

    #[DataProvider('fixtures')]
    public function testDecryptsThirdPartyFixture(string $file, string $password): void
    {
        $path = self::DIR . $file;
        if (!is_file($path)) {
            self::markTestSkipped(
                "legacy encryption fixture {$file} absent; regenerate with pikepdf (C:/tmp/encrypt.py) to run this case",
            );
        }

        $reader = PdfReader::fromBytes((string) file_get_contents($path), $password);
        self::assertTrue($reader->isEncrypted(), "{$file} should be detected as encrypted");

        // /Info /Title decrypts to the known marker.
        self::assertStringContainsString(
            'Confidential marker',
            self::resolveTitle($reader),
            "{$file}: decrypted /Info /Title should contain the marker",
        );

        // The page content stream decrypts (then decompresses) to the visible text.
        self::assertStringContainsString(
            'SECRET-XYZ-123',
            self::pageContent($reader),
            "{$file}: decrypted page content should contain the visible marker",
        );
    }

    private static function resolveTitle(PdfReader $reader): string
    {
        $infoRef = $reader->trailer()->get(Name::of('Info'));
        self::assertNotNull($infoRef);
        $info = $reader->resolve($infoRef);
        self::assertInstanceOf(Dictionary::class, $info);

        $titleRef = $info->get(Name::of('Title'));
        self::assertNotNull($titleRef);
        return self::decodeTextString($reader->resolve($titleRef));
    }

    private static function pageContent(PdfReader $reader): string
    {
        $page = $reader->page(1);
        $content = '';
        foreach ($page->contents as $ref) {
            $stream = $reader->resolve($ref);
            if ($stream instanceof ReadStream) {
                $content .= $reader->decodeStream($stream);
            }
        }
        return $content;
    }

    /**
     * UTF-8 view of a decrypted text string (UTF-16BE with a \xFE\xFF BOM, or
     * raw PDFDocEncoding/ASCII when the BOM is absent).
     */
    private static function decodeTextString(PdfObject $value): string
    {
        if ($value instanceof HexString) {
            $bin = hex2bin($value->hex());
            self::assertIsString($bin);
            $raw = $bin;
        } elseif ($value instanceof PdfString) {
            $raw = $value->value();
        } else {
            $raw = '';
        }

        if (str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }

        return $raw;
    }
}
