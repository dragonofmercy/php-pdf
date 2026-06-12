<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\PdfEditor;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use DragonOfMercy\PhpPdf\Reader\ReadStream;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\HexString;
use DragonOfMercy\PhpPdf\Writer\Object\Name;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;
use DragonOfMercy\PhpPdf\Writer\Object\PdfString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The DECISIVE cross-tool validation for editing an ENCRYPTED PDF. Each fixture
 * is a pikepdf-produced encrypted file (an independent implementation) under one
 * of the three live schemes:
 *
 *   rc4-128-pw  R3  RC4 128-bit  user password 'test'
 *   aes-128-pw  R4  AES-128      user password 'test'
 *   aes-256-pw  R6  AES-256      user password 'test'
 *
 * The source plaintext is the standard library marker document: /Info /Title
 * 'Confidential marker', page-1 text 'SECRET-XYZ-123'.
 *
 * For each scheme we open the file in {@see PdfEditor}, change the title, and
 * write the appended (re-encrypted) incremental revision. Two anchors then run:
 *
 *  1. OUR-READER round-trip (always): the new /Info /Title decrypts to the edited
 *     value AND the untouched original page-1 content still decrypts to its
 *     marker - proving the appended revision is valid and the original revision
 *     is intact.
 *  2. CROSS-TOOL (pikepdf) round-trip (skipped only when python/pikepdf is
 *     unavailable): pikepdf must open the edited file with the password WITHOUT
 *     error and read back the edited title. Because pikepdf is a foreign
 *     implementation, this is the decisive proof that our re-encrypted revision
 *     (per-object key derivation, AES IV, /Encrypt + /ID trailer forwarding) is
 *     genuinely valid for RC4, AES-128 and AES-256.
 *
 * Unit 1 validated DECRYPTION against these same fixtures; this validates the
 * inverse - that our re-ENCRYPTION is accepted by the foreign tool.
 */
final class PdfEditorEncryptedRoundtripTest extends TestCase
{
    private const string DIR = __DIR__ . '/assets/encrypted/';
    private const string PIKEPDF_PATH = 'C:/tmp/pyenc';
    private const string EDITED_TITLE = 'Edited-MARKER-XYZ';

    /** @return iterable<string, array{string, string}> file => password */
    public static function fixtures(): iterable
    {
        yield 'rc4-128-pw' => ['rc4-128-pw.pdf', 'test'];
        yield 'aes-128-pw' => ['aes-128-pw.pdf', 'test'];
        yield 'aes-256-pw' => ['aes-256-pw.pdf', 'test'];
    }

    #[DataProvider('fixtures')]
    public function testEditedEncryptedFixtureRoundTrips(string $file, string $password): void
    {
        $path = self::DIR . $file;
        if (!is_file($path)) {
            self::markTestSkipped(
                "encryption fixture {$file} absent; regenerate with pikepdf (C:/tmp/encrypt.py) to run this case",
            );
        }

        $editor = PdfEditor::fromBytes((string) file_get_contents($path), $password);
        $editor->setTitle(self::EDITED_TITLE);
        $out = $editor->output();

        // --- Anchor 1: our-reader round-trip (always runs) ---
        $reader = PdfReader::fromBytes($out, $password);
        self::assertTrue($reader->isEncrypted(), "{$file}: edited file should stay encrypted");

        self::assertSame(
            self::EDITED_TITLE,
            self::resolveTitle($reader),
            "{$file}: edited /Info /Title should decrypt to the new marker",
        );

        self::assertStringContainsString(
            'SECRET-XYZ-123',
            self::pageContent($reader),
            "{$file}: original page-1 content should still decrypt (revision intact)",
        );

        // --- Anchor 2: cross-tool (pikepdf) round-trip ---
        self::assertPikepdfReadsTitle($out, $password, $file);
    }

    /**
     * Writes the edited bytes to a temp file and has pikepdf open it with the
     * password and print the /Info Title plus the concatenated page text. pikepdf
     * MUST open the file without error and report the edited title. Only a missing
     * python/pikepdf is allowed to skip; a genuine open/decrypt failure fails.
     */
    private static function assertPikepdfReadsTitle(string $pdf, string $password, string $file): void
    {
        $python = (new ExecutableFinder())->find('python') ?? (new ExecutableFinder())->find('python3');
        if ($python === null) {
            self::markTestSkipped('python not on PATH; cross-tool pikepdf anchor skipped');
        }
        if (!is_dir(self::PIKEPDF_PATH)) {
            self::markTestSkipped('pikepdf install absent at ' . self::PIKEPDF_PATH . '; cross-tool anchor skipped');
        }

        $tmpPdf = (string) tempnam(sys_get_temp_dir(), 'phppdf_enc_');
        $tmpScript = (string) tempnam(sys_get_temp_dir(), 'phppdf_pike_');
        try {
            file_put_contents($tmpPdf, $pdf);
            file_put_contents($tmpScript, self::pikepdfScript());

            $process = new Process([$python, $tmpScript, $tmpPdf, $password]);
            $process->run();

            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();

            // A missing pikepdf module is an environment skip, not a failure.
            if (str_contains($stderr, 'ModuleNotFoundError') || str_contains($stderr, 'ImportError')) {
                self::markTestSkipped('pikepdf module not importable; cross-tool anchor skipped: ' . trim($stderr));
            }

            self::assertSame(
                0,
                $process->getExitCode(),
                "{$file}: pikepdf failed to open the edited encrypted file. stdout=[{$stdout}] stderr=[{$stderr}]",
            );

            self::assertStringContainsString(
                'TITLE=' . self::EDITED_TITLE,
                $stdout,
                "{$file}: pikepdf should read the edited /Info Title. stdout=[{$stdout}] stderr=[{$stderr}]",
            );

            self::assertStringContainsString(
                'PAGEOK',
                $stdout,
                "{$file}: pikepdf should decode page-1 content (original revision intact). stdout=[{$stdout}] stderr=[{$stderr}]",
            );
        } finally {
            @unlink($tmpPdf);
            @unlink($tmpScript);
        }
    }

    /**
     * The inline python script: open(path, password=...), print the Title and a
     * PAGEOK marker iff page-1 still carries the source text. A nonzero exit (any
     * exception) signals a pikepdf rejection of our re-encrypted revision.
     */
    private static function pikepdfScript(): string
    {
        return <<<'PY'
            import sys
            sys.path.insert(0, 'C:/tmp/pyenc')
            import pikepdf

            path, password = sys.argv[1], sys.argv[2]
            with pikepdf.open(path, password=password) as pdf:
                title = str(pdf.docinfo.get('/Title', ''))
                print('TITLE=' + title)
                page = pdf.pages[0]
                data = page.Contents.read_bytes()
                if b'SECRET-XYZ-123' in data:
                    print('PAGEOK')
            PY;
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
