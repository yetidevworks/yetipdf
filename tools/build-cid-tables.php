<?php

declare(strict_types=1);

/**
 * Builds data/cid/*.bin and data/cid/LICENSE from Adobe's published character collection tables.
 *
 *     php tools/build-cid-tables.php [--cache=DIR] [--out=DIR]
 *
 * Sources, both under Adobe's BSD 3-Clause license and pinned to the commits below so a rebuild gives the same files:
 *
 *  - mapping-resources-pdf/pdf2unicode/Adobe-<collection>-UCS2: the ToUnicode CMaps Adobe Acrobat uses for text
 *    extraction. They send vertical and rotated glyphs to the ordinary character, send the Kangxi radical and
 *    compatibility forms to the character people actually type, and cover more CIDs than cid2code.txt does.
 *  - cmap-resources/<collection>/cid2code.txt: used only for the few CIDs the CMap above leaves out (newer
 *    supplements, enclosed alphanumerics above U+FFFF). From it we take the first Unicode value that is not a
 *    private-use code point, preferring plain values to vertical ("v" suffixed) ones.
 *
 * With --cache, downloaded files are kept in (and read from) DIR, so nothing is fetched twice. The output does not
 * depend on the clock or the machine, so two runs give identical files (given the same zlib, which sets the deflate bytes).
 *
 * File format, one file per ordering (all numbers big-endian):
 *
 *     "YCD1"  u32 length of the inflated payload  raw deflate of the payload
 *
 *     payload: u32 count                       CIDs 0 .. count-1
 *              count bytes                     high byte of each CID's value
 *              count bytes                     middle byte
 *              count bytes                     low byte
 *              string area                     entries of one length byte plus UTF-8 text
 *
 *     value 0                  the CID has no text
 *     value 1 .. 0x10FFFF      one code point
 *     value 0x110000 + n       the string entry at offset n of the string area (ligatures, "アパート", and so on)
 *
 * Splitting the value into three planes makes it compress about twice as well as three interleaved bytes.
 */

const MAPPING_RESOURCES = 'https://raw.githubusercontent.com/adobe-type-tools/mapping-resources-pdf/2dd5e53fb74a01718b9dfd448a0d1cce6fff2aa5/';
const CMAP_RESOURCES = 'https://raw.githubusercontent.com/adobe-type-tools/cmap-resources/f5cf3bca7fdfeaceb77aa82847e974f2306c20b4/';

/** ordering name as written in /CIDSystemInfo => [pdf2unicode file, cmap-resources directory] */
const COLLECTIONS = [
    'Japan1' => ['Adobe-Japan1-UCS2', 'Adobe-Japan1-7'],
    'GB1' => ['Adobe-GB1-UCS2', 'Adobe-GB1-6'],
    'CNS1' => ['Adobe-CNS1-UCS2', 'Adobe-CNS1-7'],
    'Korea1' => ['Adobe-Korea1-UCS2', 'Adobe-Korea1-2'],
    'KR' => ['Adobe-KR-UCS2', 'Adobe-KR-9'],
];

const VALUE_STRING_BASE = 0x110000;

function fail(string $message): never
{
    fwrite(STDERR, "build-cid-tables: $message\n");
    exit(1);
}

function fetch(string $url, ?string $cache, string $name): string
{
    $path = $cache === null ? null : $cache . '/' . str_replace('/', '__', $name);
    if ($path !== null && is_file($path)) {
        return (string)file_get_contents($path);
    }
    $data = @file_get_contents($url . $name, false, stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'yetipdf-build']]));
    if ($data === false || $data === '') {
        fail("could not download $url$name");
    }
    if ($path !== null) {
        file_put_contents($path, $data);
    }
    return $data;
}

function utf8(int $cp): string
{
    return mb_chr($cp, 'UTF-8') ?: '';
}

/** UTF-16BE hex such as "9022db40dd00" to UTF-8, with variation selectors dropped (they pick a glyph, not a character). */
function textFromHex(string $hex): ?string
{
    $bytes = hex2bin(strlen($hex) % 2 ? '0' . $hex : $hex);
    if ($bytes === false || $bytes === '') {
        return null;
    }
    $text = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
    $text = preg_replace('/[\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u', '', $text) ?? '';
    if ($text === '' || str_contains($text, "\0") || str_contains($text, "\u{FFFD}")) {
        return null;
    }
    return $text;
}

/**
 * A pdf2unicode ToUnicode CMap as CID => UTF-8 text.
 *
 * @return array<int, string>
 */
function parseToUnicode(string $data): array
{
    $map = [];
    preg_match_all('/begin(bfchar|bfrange)\s(.*?)end\1/s', $data, $sections, PREG_SET_ORDER);
    foreach ($sections as [, $kind, $body]) {
        foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
            if ($kind === 'bfchar') {
                if (!preg_match('/^<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]*)>/', $line, $m)) {
                    continue;
                }
                $text = textFromHex($m[2]);
                if ($text !== null) {
                    $map[(int)hexdec($m[1])] = $text;
                }
            } elseif (preg_match('/^<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $line, $m)) {
                // The last UTF-16 unit counts up across the range.
                $lo = (int)hexdec($m[1]);
                $units = str_split($m[3], 4);
                $last = (int)hexdec((string)array_pop($units));
                for ($cid = $lo, $hi = (int)hexdec($m[2]); $cid <= $hi; $cid++) {
                    $text = textFromHex(implode('', $units) . sprintf('%04x', $last + $cid - $lo));
                    if ($text !== null) {
                        $map[$cid] = $text;
                    }
                }
            } elseif (preg_match('/^<[0-9a-fA-F]+>\s*<[0-9a-fA-F]+>\s*\[/', $line)) {
                fail('bfrange with an array destination is not handled: ' . $line);
            }
        }
    }
    return $map;
}

/**
 * CID => UTF-8 text from cid2code.txt, for the CIDs in $missing only.
 *
 * @param list<int> $missing
 * @return array<int, string>
 */
function fillFromCid2Code(string $data, array $missing): array
{
    $want = array_flip($missing);
    $columns = null;
    $found = [];
    foreach (preg_split('/\R/', $data) ?: [] as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $fields = explode("\t", $line);
        if ($columns === null) {
            $columns = [];
            foreach ($fields as $i => $name) {
                if (str_contains($name, 'UTF32')) {
                    $columns[] = $i;
                }
            }
            continue;
        }
        $cid = (int)$fields[0];
        if (!isset($want[$cid])) {
            continue;
        }
        $plain = $vertical = null;
        foreach ($columns as $i) {
            foreach (explode(',', trim($fields[$i] ?? '*')) as $value) {
                $isVertical = str_ends_with($value, 'v');
                $hex = rtrim($value, 'v');
                if (!preg_match('/^[0-9a-f]{8}$/', $hex)) {
                    continue;
                }
                $cp = (int)hexdec($hex);
                if (($cp >= 0xE000 && $cp <= 0xF8FF) || $cp >= 0xF0000 || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
                    continue;
                }
                if ($isVertical) {
                    $vertical ??= $cp;
                } else {
                    $plain ??= $cp;
                }
            }
        }
        $cp = $plain ?? $vertical;
        if ($cp !== null) {
            $found[$cid] = utf8($cp);
        }
    }
    return $found;
}

function lastCid(string $cid2code): int
{
    $last = 0;
    foreach (preg_split('/\R/', $cid2code) ?: [] as $line) {
        if ($line !== '' && $line[0] !== '#') {
            $last = max($last, (int)$line);
        }
    }
    return $last;
}

/** @param array<int, string> $map */
function encodeTable(array $map): string
{
    $count = $map === [] ? 0 : max(array_keys($map)) + 1;
    $planes = [str_repeat("\0", $count), str_repeat("\0", $count), str_repeat("\0", $count)];
    $strings = '';
    $offsets = [];
    ksort($map);
    foreach ($map as $cid => $text) {
        if (mb_strlen($text, 'UTF-8') === 1) {
            $value = mb_ord($text, 'UTF-8');
        } else {
            if (strlen($text) > 255) {
                fail("text for CID $cid is too long");
            }
            if (!isset($offsets[$text])) {
                $offsets[$text] = strlen($strings);
                $strings .= chr(strlen($text)) . $text;
            }
            $value = VALUE_STRING_BASE + $offsets[$text];
        }
        if ($value > 0xFFFFFF) {
            fail('string area is too large');
        }
        $planes[0][$cid] = chr($value >> 16);
        $planes[1][$cid] = chr(($value >> 8) & 0xFF);
        $planes[2][$cid] = chr($value & 0xFF);
    }
    $payload = pack('N', $count) . $planes[0] . $planes[1] . $planes[2] . $strings;
    $deflated = gzdeflate($payload, 9);
    if ($deflated === false) {
        fail('deflate failed');
    }
    return 'YCD1' . pack('N', strlen($payload)) . $deflated;
}

function main(array $argv): void
{
    $cache = null;
    $out = dirname(__DIR__) . '/data/cid';
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--cache=')) {
            $cache = rtrim(substr($arg, 8), '/');
            is_dir($cache) || mkdir($cache, 0777, true) || fail("cannot create $cache");
        } elseif (str_starts_with($arg, '--out=')) {
            $out = rtrim(substr($arg, 6), '/');
        } else {
            fail("unknown argument $arg");
        }
    }
    foreach (['mbstring', 'zlib'] as $ext) {
        extension_loaded($ext) || fail("the $ext extension is required");
    }
    is_dir($out) || mkdir($out, 0777, true) || fail("cannot create $out");

    $total = 0;
    foreach (COLLECTIONS as $ordering => [$ucs2, $dir]) {
        $map = parseToUnicode(fetch(MAPPING_RESOURCES, $cache, "pdf2unicode/$ucs2"));
        $cid2code = fetch(CMAP_RESOURCES, $cache, "$dir/cid2code.txt");
        $last = lastCid($cid2code);
        $missing = [];
        for ($cid = 1; $cid <= $last; $cid++) {
            if (!isset($map[$cid])) {
                $missing[] = $cid;
            }
        }
        $filled = fillFromCid2Code($cid2code, $missing);
        $fromCmap = count($map);
        $map += $filled;
        unset($map[0]);

        $file = encodeTable($map);
        file_put_contents("$out/$ordering.bin", $file);
        $total += strlen($file);
        printf("%-7s %6d CIDs mapped (%d from the CMap, %d from cid2code.txt) of %d, %d bytes\n", $ordering, count($map), $fromCmap, count($filled), $last, strlen($file));
    }

    $license = rtrim(fetch(CMAP_RESOURCES, $cache, 'LICENSE.md')) . "\n";
    $header = <<<TXT
        The files in this directory are derived from Adobe's published CID-to-Unicode tables for the
        Adobe-Japan1, Adobe-GB1, Adobe-CNS1, Adobe-Korea1 and Adobe-KR character collections. They are
        built by tools/build-cid-tables.php from:

          https://github.com/adobe-type-tools/mapping-resources-pdf (pdf2unicode), commit 2dd5e53
          https://github.com/adobe-type-tools/cmap-resources (cid2code.txt), commit f5cf3bc

        and are distributed under the license of those sources, reproduced below.

        -----------------------------------------------------------------------------

        TXT;
    file_put_contents("$out/LICENSE", $header . $license);
    printf("total %d bytes\n", $total);
}

main($argv);
