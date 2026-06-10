<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Reader;

use DragonOfMercy\PhpPdf\Exception\PdfParseException;
use DragonOfMercy\PhpPdf\Reader\Filter\StreamDecoder;
use DragonOfMercy\PhpPdf\Writer\Object\Dictionary;
use DragonOfMercy\PhpPdf\Writer\Object\PdfObject;

/**
 * Locates and parses the cross-reference data of a PDF file: classic xref
 * tables and cross-reference streams, following /Prev chains across
 * incremental revisions and /XRefStm in hybrid-reference files.
 *
 * @internal
 */
final readonly class XrefReader
{
    private const int STARTXREF_SEARCH_WINDOW = 2048;
    private const int XREF_RECOVERY_WINDOW = 512;

    public function __construct(
        private string $bytes,
        private int $headerOffset,
    ) {}

    public function read(): XrefData
    {
        $entries = [];
        $trailer = Dictionary::empty();
        $pending = [$this->findStartxref()];
        $visited = [];
        while ($pending !== []) {
            $offset = array_shift($pending);
            if (isset($visited[$offset])) {
                continue; // /Prev loop guard
            }
            $visited[$offset] = true;
            $section = $this->readSection($offset);
            foreach ($section->entries as $objectNumber => $entry) {
                $entries[$objectNumber] ??= $entry; // first seen (newest revision) wins
            }
            foreach ($section->trailer->entries() as [$name, $value]) {
                if ($trailer->get($name) === null) {
                    $trailer = $trailer->withEntry($name, $value);
                }
            }
            // hybrid-reference file: the /XRefStm section supplements this
            // revision and must be consulted BEFORE older /Prev revisions
            $xrefStm = DictReader::int($section->trailer, 'XRefStm');
            if ($xrefStm !== null) {
                $pending[] = $xrefStm;
            }
            $prev = DictReader::int($section->trailer, 'Prev');
            if ($prev !== null) {
                $pending[] = $prev;
            }
        }
        ksort($entries);
        return new XrefData($entries, $trailer);
    }

    private function findStartxref(): int
    {
        $windowStart = max(0, strlen($this->bytes) - self::STARTXREF_SEARCH_WINDOW);
        $tail = substr($this->bytes, $windowStart);
        $position = strrpos($tail, 'startxref');
        if ($position === false) {
            throw new PdfParseException(sprintf(
                'startxref not found in the last %d bytes of the file',
                self::STARTXREF_SEARCH_WINDOW,
            ));
        }
        $lexer = new Lexer($this->bytes, $windowStart + $position + strlen('startxref'));
        $token = $lexer->next();
        if ($token->type !== TokenType::Integer) {
            throw new PdfParseException("Expected an integer after startxref at offset {$token->offset}, got '{$token->lexeme}'");
        }
        return $token->toInt();
    }

    private function readSection(int $offset): XrefData
    {
        $absolute = $this->headerOffset + $offset;
        if ($absolute < 0 || $absolute >= strlen($this->bytes)) {
            throw new PdfParseException("xref offset {$offset} is outside the file");
        }
        $lexer = new Lexer($this->bytes, $absolute);
        $first = $lexer->peek();
        if ($first->isKeyword('xref')) {
            return $this->readClassicSection($lexer);
        }
        if ($first->type === TokenType::Integer) {
            // The offset points to what looks like an indirect object - parse it as an xref stream.
            // Content-level errors (bad /W, truncated data) must propagate unchanged.
            return $this->readStreamSection($lexer, $offset);
        }
        // The offset is wrong: no "xref" keyword and no object-number integer.
        // Scan nearby for a classic xref table.
        return $this->recoverXrefNear($absolute, $offset);
    }

    /**
     * Leniency: the startxref value is slightly wrong (a few bytes off, e.g.
     * because another tool inserted bytes without adjusting the offset). Scan
     * a window around the recorded position for a classic "xref" table header.
     *
     * A match of 'xref' is only accepted as a keyword when the byte immediately
     * preceding it in the file is NOT an ASCII letter - this prevents matching
     * the 'xref' tail inside the word 'startxref'.
     */
    private function recoverXrefNear(int $absolute, int $offset): XrefData
    {
        $windowStart = max($this->headerOffset, $absolute - self::XREF_RECOVERY_WINDOW);
        $window = substr($this->bytes, $windowStart, 2 * self::XREF_RECOVERY_WINDOW);
        $search = 0;
        while (($found = strpos($window, 'xref', $search)) !== false) {
            // Determine the byte that precedes this 'xref' match in the full file.
            $filePos = $windowStart + $found;
            if ($filePos > 0 && ctype_alpha($this->bytes[$filePos - 1])) {
                // Preceded by an alpha character - this is the tail of a word like
                // 'startxref', not a standalone keyword; skip it.
                $search = $found + 1;
                continue;
            }
            $lexer = new Lexer($this->bytes, $filePos);
            if ($lexer->peek()->isKeyword('xref')) {
                return $this->readClassicSection($lexer);
            }
            $search = $found + 1;
        }
        throw new PdfParseException("xref section not found near offset {$offset} (recovery scan failed)");
    }

    private function readStreamSection(Lexer $lexer, int $offset): XrefData
    {
        $object = (new ObjectParser($lexer))->parseIndirectObjectAt($lexer->position());
        $payload = $object->payload();
        if (!$payload instanceof ReadStream) {
            throw new PdfParseException("Expected an xref stream object at offset {$offset}");
        }
        $dict = $payload->dict;
        // No resolver needed: xref stream dict entries are direct per spec.
        $widths = DictReader::intList($dict, 'W');
        if ($widths === null || count($widths) < 3) {
            throw new PdfParseException("xref stream at offset {$offset} has a missing or malformed /W entry");
        }
        $size = DictReader::int($dict, 'Size');
        if ($size === null) {
            throw new PdfParseException("xref stream at offset {$offset} has no /Size entry");
        }
        $index = DictReader::intList($dict, 'Index') ?? [0, $size];
        if (count($index) % 2 !== 0) {
            throw new PdfParseException("xref stream at offset {$offset} has an odd /Index entry");
        }

        $data = (new StreamDecoder())->decode($payload, static fn (PdfObject $o): PdfObject => $o);
        $rowLength = $widths[0] + $widths[1] + $widths[2];
        if ($rowLength <= 0) {
            throw new PdfParseException("xref stream at offset {$offset} has zero-width rows");
        }

        $entries = [];
        $position = 0;
        $available = strlen($data);
        $indexCount = count($index);
        for ($pair = 0; $pair < $indexCount; $pair += 2) {
            $start = $index[$pair];
            $count = $index[$pair + 1];
            for ($i = 0; $i < $count; $i++) {
                if ($position + $rowLength > $available) {
                    throw new PdfParseException(sprintf(
                        'Truncated xref stream at offset %d: needed %d more rows of %d bytes',
                        $offset,
                        $count - $i,
                        $rowLength,
                    ));
                }
                $type = $widths[0] === 0 ? 1 : $this->bigEndian($data, $position, $widths[0]);
                $field2 = $this->bigEndian($data, $position + $widths[0], $widths[1]);
                $field3 = $this->bigEndian($data, $position + $widths[0] + $widths[1], $widths[2]);
                $position += $rowLength;
                $objectNumber = $start + $i;
                $entry = match ($type) {
                    0 => XrefEntry::free(),
                    1 => XrefEntry::inFile($field2, $field3),
                    2 => XrefEntry::inObjectStream($field2, $field3),
                    default => null, // unknown types must be treated as absent (7.5.8.3)
                };
                if ($entry !== null) {
                    $entries[$objectNumber] ??= $entry;
                }
            }
        }
        return new XrefData($entries, $dict);
    }

    private function bigEndian(string $data, int $offset, int $width): int
    {
        if ($width > 8) {
            throw new PdfParseException("xref stream field width {$width} exceeds 8 bytes");
        }
        $value = 0;
        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 8) | ord($data[$offset + $i]);
        }
        return $value;
    }

    private function readClassicSection(Lexer $lexer): XrefData
    {
        $lexer->next(); // consume 'xref'
        $entries = [];
        while (true) {
            $token = $lexer->next();
            if ($token->isKeyword('trailer')) {
                break;
            }
            if ($token->type !== TokenType::Integer) {
                throw new PdfParseException("Expected a subsection start or 'trailer' at offset {$token->offset}, got '{$token->lexeme}'");
            }
            $start = $token->toInt();
            $countToken = $lexer->next();
            if ($countToken->type !== TokenType::Integer) {
                throw new PdfParseException("Expected a subsection count at offset {$countToken->offset}, got '{$countToken->lexeme}'");
            }
            $count = $countToken->toInt();
            for ($i = 0; $i < $count; $i++) {
                $field1 = $lexer->next();
                $field2 = $lexer->next();
                $kind = $lexer->next();
                if ($field1->type !== TokenType::Integer || $field2->type !== TokenType::Integer) {
                    throw new PdfParseException("Malformed xref entry at offset {$field1->offset}");
                }
                $objectNumber = $start + $i;
                if ($kind->isKeyword('n')) {
                    $entries[$objectNumber] ??= XrefEntry::inFile($field1->toInt(), $field2->toInt());
                } elseif ($kind->isKeyword('f')) {
                    $entries[$objectNumber] ??= XrefEntry::free();
                } else {
                    throw new PdfParseException("Expected 'n' or 'f' in xref entry at offset {$kind->offset}, got '{$kind->lexeme}'");
                }
            }
        }
        $trailer = (new ObjectParser($lexer))->parseObject();
        if (!$trailer instanceof Dictionary) {
            throw new PdfParseException('The trailer is not a dictionary');
        }
        return new XrefData($entries, $trailer);
    }
}
