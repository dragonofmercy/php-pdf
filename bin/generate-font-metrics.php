<?php

declare(strict_types=1);

/**
 * Generates build/src/Font/Metrics/*.php from Adobe AFM files placed in
 * build/bin/afm-source/. Source AFMs are NOT committed to the repo
 * (.gitignore excludes them); fetch them from the Adobe Core14 AFMs project
 * before running this script.
 *
 * Usage: php build/bin/generate-font-metrics.php
 */

const AFM_DIR = __DIR__ . '/afm-source';
const OUT_DIR = __DIR__ . '/../src/Font/Metrics';

/**
 * AFM file name (without .afm) => output PHP file name (without .php).
 * The 12 of the 14 standard PDF fonts that phppdf supports (Symbol and
 * ZapfDingbats are excluded -- different encoding).
 */
const FONTS = [
    'Helvetica' => 'Helvetica',
    'Helvetica-Bold' => 'HelveticaBold',
    'Helvetica-Oblique' => 'HelveticaOblique',
    'Helvetica-BoldOblique' => 'HelveticaBoldOblique',
    'Times-Roman' => 'TimesRoman',
    'Times-Bold' => 'TimesBold',
    'Times-Italic' => 'TimesItalic',
    'Times-BoldItalic' => 'TimesBoldItalic',
    'Courier' => 'Courier',
    'Courier-Bold' => 'CourierBold',
    'Courier-Oblique' => 'CourierOblique',
    'Courier-BoldOblique' => 'CourierBoldOblique',
];

/**
 * WinAnsi encoding map: Adobe glyph name => byte position (0x00..0xFF).
 * Covers ASCII (0x20..0x7E), the 27 typographic chars at 0x80..0x9F (per PDF
 * Annex D.2), and Latin-1 supplement (0xA0..0xFF). Positions 0x7F, 0x81,
 * 0x8D, 0x8F, 0x90, 0x9D are undefined in WinAnsi (no entry).
 */
const GLYPH_TO_WINANSI = [
    'space' => 0x20, 'exclam' => 0x21, 'quotedbl' => 0x22, 'numbersign' => 0x23,
    'dollar' => 0x24, 'percent' => 0x25, 'ampersand' => 0x26, 'quoteright' => 0x27,
    'parenleft' => 0x28, 'parenright' => 0x29, 'asterisk' => 0x2A, 'plus' => 0x2B,
    'comma' => 0x2C, 'hyphen' => 0x2D, 'period' => 0x2E, 'slash' => 0x2F,
    'zero' => 0x30, 'one' => 0x31, 'two' => 0x32, 'three' => 0x33, 'four' => 0x34,
    'five' => 0x35, 'six' => 0x36, 'seven' => 0x37, 'eight' => 0x38, 'nine' => 0x39,
    'colon' => 0x3A, 'semicolon' => 0x3B, 'less' => 0x3C, 'equal' => 0x3D,
    'greater' => 0x3E, 'question' => 0x3F, 'at' => 0x40,
    'A' => 0x41, 'B' => 0x42, 'C' => 0x43, 'D' => 0x44, 'E' => 0x45, 'F' => 0x46,
    'G' => 0x47, 'H' => 0x48, 'I' => 0x49, 'J' => 0x4A, 'K' => 0x4B, 'L' => 0x4C,
    'M' => 0x4D, 'N' => 0x4E, 'O' => 0x4F, 'P' => 0x50, 'Q' => 0x51, 'R' => 0x52,
    'S' => 0x53, 'T' => 0x54, 'U' => 0x55, 'V' => 0x56, 'W' => 0x57, 'X' => 0x58,
    'Y' => 0x59, 'Z' => 0x5A,
    'bracketleft' => 0x5B, 'backslash' => 0x5C, 'bracketright' => 0x5D,
    'asciicircum' => 0x5E, 'underscore' => 0x5F, 'quoteleft' => 0x60,
    'a' => 0x61, 'b' => 0x62, 'c' => 0x63, 'd' => 0x64, 'e' => 0x65, 'f' => 0x66,
    'g' => 0x67, 'h' => 0x68, 'i' => 0x69, 'j' => 0x6A, 'k' => 0x6B, 'l' => 0x6C,
    'm' => 0x6D, 'n' => 0x6E, 'o' => 0x6F, 'p' => 0x70, 'q' => 0x71, 'r' => 0x72,
    's' => 0x73, 't' => 0x74, 'u' => 0x75, 'v' => 0x76, 'w' => 0x77, 'x' => 0x78,
    'y' => 0x79, 'z' => 0x7A,
    'braceleft' => 0x7B, 'bar' => 0x7C, 'braceright' => 0x7D, 'asciitilde' => 0x7E,
    'Euro' => 0x80, 'quotesinglbase' => 0x82, 'florin' => 0x83, 'quotedblbase' => 0x84,
    'ellipsis' => 0x85, 'dagger' => 0x86, 'daggerdbl' => 0x87, 'circumflex' => 0x88,
    'perthousand' => 0x89, 'Scaron' => 0x8A, 'guilsinglleft' => 0x8B, 'OE' => 0x8C,
    'Zcaron' => 0x8E,
    'quotedblleft' => 0x93, 'quotedblright' => 0x94, 'bullet' => 0x95,
    'endash' => 0x96, 'emdash' => 0x97, 'tilde' => 0x98, 'trademark' => 0x99,
    'scaron' => 0x9A, 'guilsinglright' => 0x9B, 'oe' => 0x9C, 'zcaron' => 0x9E,
    'Ydieresis' => 0x9F,
    'exclamdown' => 0xA1, 'cent' => 0xA2, 'sterling' => 0xA3,
    'currency' => 0xA4, 'yen' => 0xA5, 'brokenbar' => 0xA6, 'section' => 0xA7,
    'dieresis' => 0xA8, 'copyright' => 0xA9, 'ordfeminine' => 0xAA,
    'guillemotleft' => 0xAB, 'logicalnot' => 0xAC,
    'registered' => 0xAE, 'macron' => 0xAF,
    'degree' => 0xB0, 'plusminus' => 0xB1, 'twosuperior' => 0xB2,
    'threesuperior' => 0xB3, 'acute' => 0xB4, 'mu' => 0xB5, 'paragraph' => 0xB6,
    'periodcentered' => 0xB7, 'cedilla' => 0xB8, 'onesuperior' => 0xB9,
    'ordmasculine' => 0xBA, 'guillemotright' => 0xBB, 'onequarter' => 0xBC,
    'onehalf' => 0xBD, 'threequarters' => 0xBE, 'questiondown' => 0xBF,
    'Agrave' => 0xC0, 'Aacute' => 0xC1, 'Acircumflex' => 0xC2, 'Atilde' => 0xC3,
    'Adieresis' => 0xC4, 'Aring' => 0xC5, 'AE' => 0xC6, 'Ccedilla' => 0xC7,
    'Egrave' => 0xC8, 'Eacute' => 0xC9, 'Ecircumflex' => 0xCA, 'Edieresis' => 0xCB,
    'Igrave' => 0xCC, 'Iacute' => 0xCD, 'Icircumflex' => 0xCE, 'Idieresis' => 0xCF,
    'Eth' => 0xD0, 'Ntilde' => 0xD1, 'Ograve' => 0xD2, 'Oacute' => 0xD3,
    'Ocircumflex' => 0xD4, 'Otilde' => 0xD5, 'Odieresis' => 0xD6, 'multiply' => 0xD7,
    'Oslash' => 0xD8, 'Ugrave' => 0xD9, 'Uacute' => 0xDA, 'Ucircumflex' => 0xDB,
    'Udieresis' => 0xDC, 'Yacute' => 0xDD, 'Thorn' => 0xDE, 'germandbls' => 0xDF,
    'agrave' => 0xE0, 'aacute' => 0xE1, 'acircumflex' => 0xE2, 'atilde' => 0xE3,
    'adieresis' => 0xE4, 'aring' => 0xE5, 'ae' => 0xE6, 'ccedilla' => 0xE7,
    'egrave' => 0xE8, 'eacute' => 0xE9, 'ecircumflex' => 0xEA, 'edieresis' => 0xEB,
    'igrave' => 0xEC, 'iacute' => 0xED, 'icircumflex' => 0xEE, 'idieresis' => 0xEF,
    'eth' => 0xF0, 'ntilde' => 0xF1, 'ograve' => 0xF2, 'oacute' => 0xF3,
    'ocircumflex' => 0xF4, 'otilde' => 0xF5, 'odieresis' => 0xF6, 'divide' => 0xF7,
    'oslash' => 0xF8, 'ugrave' => 0xF9, 'uacute' => 0xFA, 'ucircumflex' => 0xFB,
    'udieresis' => 0xFC, 'yacute' => 0xFD, 'thorn' => 0xFE, 'ydieresis' => 0xFF,
];

function parseAfm(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Cannot read AFM: {$path}");
    }
    $lines = preg_split('/\r\n|\r|\n/', $contents);

    $ascent = 0;
    $descent = 0;
    $capHeight = 0;
    $xHeight = 0;
    $widths = [];

    foreach ($lines as $line) {
        if (preg_match('/^Ascender\s+(-?\d+)/', $line, $m)) {
            $ascent = (int) $m[1];
        }
        if (preg_match('/^Descender\s+(-?\d+)/', $line, $m)) {
            $descent = (int) $m[1];
        }
        if (preg_match('/^CapHeight\s+(-?\d+)/', $line, $m)) {
            $capHeight = (int) $m[1];
        }
        if (preg_match('/^XHeight\s+(-?\d+)/', $line, $m)) {
            $xHeight = (int) $m[1];
        }
        if (preg_match('/^C\s+(-?\d+)\s*;\s*WX\s+(-?\d+)\s*;\s*N\s+([\w.]+)\s*;/', $line, $m)) {
            $glyphName = $m[3];
            $width = (int) $m[2];

            // Special-case quoteleft/quoteright: they appear at both
            // 0x60/0x27 (ASCII) and 0x91/0x92 (typographic) in WinAnsi.
            if ($glyphName === 'quoteleft') {
                $widths[0x60] = $width;
                $widths[0x91] = $width;
            } elseif ($glyphName === 'quoteright') {
                $widths[0x27] = $width;
                $widths[0x92] = $width;
            } elseif (isset(GLYPH_TO_WINANSI[$glyphName])) {
                $widths[GLYPH_TO_WINANSI[$glyphName]] = $width;
            }
        }
    }

    if ($ascent === 0 && $capHeight !== 0) {
        // Some AFMs lack Ascender; fall back to CapHeight (Times-Roman, e.g.).
        $ascent = $capHeight;
    }

    ksort($widths);

    return [
        'ascent' => $ascent,
        'descent' => $descent,
        'capHeight' => $capHeight,
        'xHeight' => $xHeight,
        'missingWidth' => 0,
        'widths' => $widths,
    ];
}

function emitPhp(string $afmName, array $data): string
{
    $out = "<?php\n\n";
    $out .= "// Generated from {$afmName}.afm. Do not edit by hand.\n";
    $out .= "// Source: Adobe Core14 AFMs (https://github.com/adobe-type-tools/Core14_AFMs).\n\n";
    $out .= "declare(strict_types=1);\n\n";
    $out .= "return [\n";
    $out .= sprintf("    'ascent' => %d,\n", $data['ascent']);
    $out .= sprintf("    'descent' => %d,\n", $data['descent']);
    $out .= sprintf("    'capHeight' => %d,\n", $data['capHeight']);
    $out .= sprintf("    'xHeight' => %d,\n", $data['xHeight']);
    $out .= sprintf("    'missingWidth' => %d,\n", $data['missingWidth']);
    $out .= "    'widths' => [\n";
    foreach ($data['widths'] as $byte => $width) {
        $out .= sprintf("        0x%02X => %d,\n", $byte, $width);
    }
    $out .= "    ],\n";
    $out .= "];\n";
    return $out;
}

if (!is_dir(AFM_DIR)) {
    fwrite(STDERR, "Source dir not found: " . AFM_DIR . "\n");
    fwrite(STDERR, "Place .afm files there and re-run.\n");
    exit(1);
}

if (!is_dir(OUT_DIR) && !mkdir(OUT_DIR, 0755, true)) {
    fwrite(STDERR, "Cannot create output dir: " . OUT_DIR . "\n");
    exit(1);
}

foreach (FONTS as $afmName => $outName) {
    $afmPath = AFM_DIR . '/' . $afmName . '.afm';
    if (!is_file($afmPath)) {
        fwrite(STDERR, "Missing: {$afmPath} -- skipping\n");
        continue;
    }
    $data = parseAfm($afmPath);
    $php = emitPhp($afmName, $data);
    $outPath = OUT_DIR . '/' . $outName . '.php';
    file_put_contents($outPath, $php);
    echo "Generated {$outPath} (" . count($data['widths']) . " widths)\n";
}
