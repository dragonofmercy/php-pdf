<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Font\Custom\Cff;

use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * Reserialises a ParsedCff to deterministic CFF bytes (Adobe TN #5176).
 * Offset operators in the Top DICT use the 5-byte long form (op 29 + uint32)
 * so the layout can be computed in two passes: pass 1 emits with placeholder
 * offsets, pass 2 patches them to the final byte positions.
 *
 * Top DICT operators are written in fixed alphabetical order by name for
 * byte determinism. CharStrings INDEX always emits numGlyphs entries
 * (closure entries hold bytes, others are empty -> length 0 entry).
 *
 * charset and FDSelect are re-emitted verbatim from the raw bytes captured
 * by CffReader to preserve the original on-disk encoding and round-trip
 * determinism.
 *
 * @internal
 */
final class CffWriter
{
    /** Inverse of CffReader::TOP_DICT_OPS (name -> opcode). */
    private const array TOP_DICT_OPCODES = [
        'version' => 0x00, 'Notice' => 0x01, 'FullName' => 0x02, 'FamilyName' => 0x03,
        'Weight' => 0x04, 'FontBBox' => 0x05, 'UniqueID' => 0x0d, 'XUID' => 0x0e,
        'charset' => 0x0f, 'Encoding' => 0x10, 'CharStrings' => 0x11, 'Private' => 0x12,
        'Copyright' => 0x0c00, 'isFixedPitch' => 0x0c01, 'ItalicAngle' => 0x0c02,
        'UnderlinePosition' => 0x0c03, 'UnderlineThickness' => 0x0c04, 'PaintType' => 0x0c05,
        'CharstringType' => 0x0c06, 'FontMatrix' => 0x0c07, 'StrokeWidth' => 0x0c08,
        'SyntheticBase' => 0x0c14, 'PostScript' => 0x0c15, 'BaseFontName' => 0x0c16,
        'BaseFontBlend' => 0x0c17, 'ROS' => 0x0c1e, 'CIDFontVersion' => 0x0c1f,
        'CIDFontRevision' => 0x0c20, 'CIDFontType' => 0x0c21, 'CIDCount' => 0x0c22,
        'UIDBase' => 0x0c23, 'FDArray' => 0x0c24, 'FDSelect' => 0x0c25, 'FontName' => 0x0c26,
    ];

    private const array PRIVATE_DICT_OPCODES = [
        'BlueValues' => 0x06, 'OtherBlues' => 0x07, 'FamilyBlues' => 0x08,
        'FamilyOtherBlues' => 0x09, 'StdHW' => 0x0a, 'StdVW' => 0x0b, 'Subrs' => 0x13,
        'defaultWidthX' => 0x14, 'nominalWidthX' => 0x15,
        'BlueScale' => 0x0c09, 'BlueShift' => 0x0c0a, 'BlueFuzz' => 0x0c0b,
        'StemSnapH' => 0x0c0c, 'StemSnapV' => 0x0c0d, 'ForceBold' => 0x0c0e,
        'LanguageGroup' => 0x0c11, 'ExpansionFactor' => 0x0c12, 'initialRandomSeed' => 0x0c13,
    ];

    /** Offset operators that need patching with final byte positions. */
    private const array OFFSET_OPS = ['charset', 'Encoding', 'CharStrings', 'Private', 'FDArray', 'FDSelect'];

    public function write(ParsedCff $cff): string
    {
        if (count($cff->topDictData) !== 1) {
            throw new PdfException('CffWriter requires exactly one Top DICT');
        }
        $td = $cff->topDictData[0];
        $topDict = $cff->topDicts[0];

        // Header
        $header = chr($cff->header->major & 0xFF)
            . chr($cff->header->minor & 0xFF)
            . chr($cff->header->hdrSize & 0xFF)
            . chr(1);

        // Name INDEX
        $nameIndex = $this->serializeIndex([$cff->nameIndexEntry]);

        // String INDEX
        $stringIndex = $this->serializeIndex($cff->stringIndex);

        // GSubrs INDEX
        $gsubrsIndex = $this->serializeIndex($cff->gsubrsIndex);

        // charset (re-emitted verbatim from captured raw bytes)
        $charsetBytes = $this->serializeCharset($td->charset);

        // CharStrings INDEX (numGlyphs entries, sparse: missing GIDs become length-0)
        $csEntries = [];
        for ($g = 0; $g < $td->charStrings->numGlyphs; $g++) {
            $csEntries[] = $td->charStrings->glyphs[$g] ?? '';
        }
        $charStringsIndex = $this->serializeIndex($csEntries);

        // Encoding (name-keyed only, optional, re-emitted verbatim when present)
        $encodingBytes = $td->encoding !== null ? $td->encoding->rawBytes : '';

        // Pass 1 - emit Top DICT with placeholder long-form offsets to fix the size.
        $placeholders = $this->initialOffsetMap($topDict, $td);
        $topDictBody = $this->serializeDict(
            $this->patchedTopDict($topDict, $placeholders),
            self::TOP_DICT_OPCODES,
            offsetOps: self::OFFSET_OPS,
        );
        $topDictIndex = $this->serializeIndex([$topDictBody]);

        // Layout positions after the Top DICT INDEX is sized.
        $cursor = strlen($header) + strlen($nameIndex) + strlen($topDictIndex)
            + strlen($stringIndex) + strlen($gsubrsIndex);

        $real = [];
        $real['charset'] = $cursor;
        $cursor += strlen($charsetBytes);
        if ($td->encoding !== null) {
            $real['Encoding'] = $cursor;
            $cursor += strlen($encodingBytes);
        }
        $real['CharStrings'] = $cursor;
        $cursor += strlen($charStringsIndex);

        $nameKeyedTail = '';
        $cidKeyedTail = '';

        if ($td->namePrivate !== null) {
            // Name-keyed: Private body sits right after CharStrings, then Subrs follows.
            [$privBody, $subrsBytes] = $this->buildPrivateAndSubrs($td->namePrivate);
            $real['Private'] = [strlen($privBody), $cursor];
            $cursor += strlen($privBody) + strlen($subrsBytes);
            $nameKeyedTail = $privBody . $subrsBytes;
        }

        if ($td->cidKeyed !== null) {
            [$cidKeyedTail, $cursor, $fdSelectOff, $fdArrayOff] =
                $this->layoutCidKeyedTail($td->cidKeyed, $cursor);
            $real['FDSelect'] = $fdSelectOff;
            $real['FDArray'] = $fdArrayOff;
        }

        // Pass 2: re-emit Top DICT with the real offsets.
        $topDictBody2 = $this->serializeDict(
            $this->patchedTopDict($topDict, $real),
            self::TOP_DICT_OPCODES,
            offsetOps: self::OFFSET_OPS,
        );
        if (strlen($topDictBody2) !== strlen($topDictBody)) {
            // Long-form offsets keep operand width fixed; any size delta is a bug.
            throw new PdfException(sprintf(
                'CffWriter internal error: Top DICT size changed between passes (%d vs %d)',
                strlen($topDictBody),
                strlen($topDictBody2),
            ));
        }
        $topDictIndex2 = $this->serializeIndex([$topDictBody2]);

        return $header
            . $nameIndex
            . $topDictIndex2
            . $stringIndex
            . $gsubrsIndex
            . $charsetBytes
            . $encodingBytes
            . $charStringsIndex
            . $nameKeyedTail
            . $cidKeyedTail;
    }

    /**
     * @param array<string, int|float|array<int, int|float>> $topDict
     * @return array<string, int|array{int, int}>
     */
    private function initialOffsetMap(array $topDict, CffTopDictData $td): array
    {
        $placeholders = [];
        if (isset($topDict['charset'])) {
            $placeholders['charset'] = 0;
        }
        if ($td->encoding !== null) {
            $placeholders['Encoding'] = 0;
        }
        if (isset($topDict['CharStrings'])) {
            $placeholders['CharStrings'] = 0;
        }
        if ($td->namePrivate !== null) {
            $placeholders['Private'] = [0, 0];
        }
        if ($td->cidKeyed !== null) {
            $placeholders['FDArray'] = 0;
            $placeholders['FDSelect'] = 0;
        }
        return $placeholders;
    }

    /**
     * @param array<string, int|float|array<int, int|float>> $topDict
     * @param array<string, int|array{int, int}>             $offsets
     * @return array<string, int|float|array<int, int|float>>
     */
    private function patchedTopDict(array $topDict, array $offsets): array
    {
        $patched = $topDict;
        foreach ($offsets as $name => $value) {
            $patched[$name] = $value;
        }
        return $patched;
    }

    /**
     * @param list<string> $entries
     */
    private function serializeIndex(array $entries): string
    {
        $count = count($entries);
        if ($count === 0) {
            return "\x00\x00";
        }
        $data = implode('', $entries);
        $maxOff = strlen($data) + 1;
        $offSize = $this->minOffSize($maxOff);
        $offsets = '';
        $cursor = 1;
        $offsets .= $this->packOffset($cursor, $offSize);
        foreach ($entries as $entry) {
            $cursor += strlen($entry);
            $offsets .= $this->packOffset($cursor, $offSize);
        }
        return pack('n', $count) . chr($offSize & 0xFF) . $offsets . $data;
    }

    private function minOffSize(int $maxOff): int
    {
        if ($maxOff < 0x100) {
            return 1;
        }
        if ($maxOff < 0x10000) {
            return 2;
        }
        if ($maxOff < 0x1000000) {
            return 3;
        }
        return 4;
    }

    private function packOffset(int $v, int $size): string
    {
        return match ($size) {
            1 => chr($v & 0xFF),
            2 => pack('n', $v),
            3 => substr(pack('N', $v), 1),
            4 => pack('N', $v),
            default => throw new PdfException("Invalid CFF offSize {$size}"),
        };
    }

    /**
     * @param array<string, int|float|array<int, int|float>> $dict
     * @param array<string, int>                             $opcodes name => opcode
     * @param list<string>                                   $offsetOps operators to write in 5-byte long form
     */
    private function serializeDict(array $dict, array $opcodes, array $offsetOps): string
    {
        $names = array_keys($dict);
        sort($names);
        $out = '';
        foreach ($names as $name) {
            if (!isset($opcodes[$name])) {
                throw new PdfException("Unknown CFF operator '{$name}' for write");
            }
            $value = $dict[$name];
            $operands = is_array($value) ? $value : [$value];
            $isOffset = in_array($name, $offsetOps, true);
            foreach ($operands as $operand) {
                if ($isOffset && is_int($operand)) {
                    $out .= "\x1d" . pack('N', $operand & 0xFFFFFFFF);
                    continue;
                }
                $out .= $this->encodeOperand($operand);
            }
            $opcode = $opcodes[$name];
            if ($opcode > 0xff) {
                $out .= chr(0x0c) . chr($opcode & 0xff);
            } else {
                $out .= chr($opcode & 0xFF);
            }
        }
        return $out;
    }

    private function encodeOperand(int|float $v): string
    {
        if (is_float($v)) {
            // Simple BCD encoding for floats (Adobe TN #5176 Table 5).
            $s = (string) $v;
            $nibbles = [];
            for ($i = 0; $i < strlen($s); $i++) {
                $c = $s[$i];
                if (ctype_digit($c)) {
                    $nibbles[] = (int) $c;
                } elseif ($c === '.') {
                    $nibbles[] = 0x0a;
                } elseif ($c === '-') {
                    $nibbles[] = 0x0e;
                } elseif ($c === 'E') {
                    $nibbles[] = 0x0b;
                }
            }
            $nibbles[] = 0x0f;
            if (count($nibbles) % 2 === 1) {
                $nibbles[] = 0x0f;
            }
            $body = '';
            for ($i = 0; $i < count($nibbles); $i += 2) {
                $body .= chr((($nibbles[$i] << 4) | $nibbles[$i + 1]) & 0xFF);
            }
            return "\x1e" . $body;
        }
        if ($v >= -107 && $v <= 107) {
            return chr(($v + 139) & 0xFF);
        }
        if ($v >= 108 && $v <= 1131) {
            $v -= 108;
            return chr((intdiv($v, 256) + 247) & 0xFF) . chr($v % 256 & 0xFF);
        }
        if ($v >= -1131 && $v <= -108) {
            $v = -$v - 108;
            return chr((intdiv($v, 256) + 251) & 0xFF) . chr($v % 256 & 0xFF);
        }
        if ($v >= -32768 && $v <= 32767) {
            return "\x1c" . pack('n', $v & 0xFFFF);
        }
        return "\x1d" . pack('N', $v & 0xFFFFFFFF);
    }

    private function serializeCharset(CffCharset $charset): string
    {
        return $charset->rawBytes;
    }

    private function serializeFdSelect(CffCidKeyed $cid): string
    {
        return $cid->fdSelectRawBytes;
    }

    /**
     * @param array<string, int|float|array<int, int|float>> $privateDict
     */
    private function serializeDictForPrivate(array $privateDict, int $subrsRelOffset): string
    {
        $patched = $privateDict;
        if (array_key_exists('Subrs', $privateDict)) {
            $patched['Subrs'] = $subrsRelOffset;
        }
        return $this->serializeDict($patched, self::PRIVATE_DICT_OPCODES, offsetOps: []);
    }

    /**
     * Builds the Private DICT body + local Subrs INDEX bytes for one Font DICT.
     * Two-pass: first serialize Private with Subrs=0 to learn its size, then
     * re-serialize with the actual Subrs relative offset (= size of the body
     * itself, since the Subrs INDEX sits immediately after).
     *
     * @return array{0: string, 1: string} privBody, subrsBytes
     */
    private function buildPrivateAndSubrs(CffNameKeyedPrivate $priv): array
    {
        $subrsBytes = $this->serializeIndex($priv->localSubrs);
        $privBody = $this->serializeDictForPrivate($priv->privateDict, 0);
        if ($priv->localSubrs !== []) {
            $privBody = $this->serializeDictForPrivate($priv->privateDict, strlen($privBody));
        }
        return [$privBody, $subrsBytes];
    }

    /**
     * Lays out the CID-keyed tail starting at $cursor, returning the assembled
     * bytes plus the offsets the Top DICT must point at.
     *
     * Layout: FDSelect | FDArray | priv0 | subrs0 | priv1 | subrs1 ...
     *
     * @return array{0: string, 1: int, 2: int, 3: int} tail bytes, end cursor, FDSelect offset, FDArray offset
     */
    private function layoutCidKeyedTail(CffCidKeyed $cid, int $cursor): array
    {
        $fdSelectBytes = $this->serializeFdSelect($cid);
        $fdSelectOffset = $cursor;
        $cursor += strlen($fdSelectBytes);

        $perFdPrivBodies = [];
        $perFdSubrs = [];
        foreach ($cid->fdPrivates as $fdp) {
            [$privBody, $subrsBytes] = $this->buildPrivateAndSubrs($fdp);
            $perFdPrivBodies[] = $privBody;
            $perFdSubrs[] = $subrsBytes;
        }

        // FDArray serialized twice: Pass A with placeholder per-FD offsets to
        // size the INDEX, Pass B with the resolved offsets. Pass A's bytes are
        // discarded but the size is needed to compute the per-FD layout.
        $fdaBytes = $this->serializeFdArray($cid->fontDicts, $perFdPrivBodies, fillOffsets: false);
        $fdArrayOffset = $cursor;
        $afterFda = $cursor + strlen($fdaBytes);
        $fdaBytes = $this->serializeFdArray(
            $cid->fontDicts,
            $perFdPrivBodies,
            fillOffsets: true,
            startOffset: $afterFda,
            subrsBytes: $perFdSubrs,
        );
        $cursor = $afterFda;

        $tail = $fdSelectBytes . $fdaBytes;
        foreach ($perFdPrivBodies as $i => $pb) {
            $tail .= $pb . $perFdSubrs[$i];
            $cursor += strlen($pb) + strlen($perFdSubrs[$i]);
        }
        return [$tail, $cursor, $fdSelectOffset, $fdArrayOffset];
    }

    /**
     * Serializes the FDArray INDEX. When $fillOffsets is false, per-FD Private
     * operators carry [size, 0] placeholders; when true, the Private offsets
     * are resolved against $startOffset + the running sum of priv+subrs sizes.
     *
     * @param list<array<string, int|float|array<int, int|float>>> $fontDicts
     * @param list<string>                                          $perFdPrivBodies
     * @param list<string>                                          $subrsBytes
     */
    private function serializeFdArray(
        array $fontDicts,
        array $perFdPrivBodies,
        bool $fillOffsets,
        int $startOffset = 0,
        array $subrsBytes = [],
    ): string {
        $privCursor = $startOffset;
        $entries = [];
        foreach ($fontDicts as $i => $fontDict) {
            $size = strlen($perFdPrivBodies[$i]);
            $patched = $fontDict;
            $patched['Private'] = [$size, $fillOffsets ? $privCursor : 0];
            $entries[] = $this->serializeDict($patched, self::TOP_DICT_OPCODES, offsetOps: ['Private']);
            if ($fillOffsets) {
                $privCursor += $size + strlen($subrsBytes[$i]);
            }
        }
        return $this->serializeIndex($entries);
    }
}
