<?php

declare(strict_types=1);

namespace YetiPdf\Font;

use YetiPdf\Core\File;
use YetiPdf\Core\PdfString;
use YetiPdf\Core\Ref;
use YetiPdf\Core\Stream;
use YetiPdf\Filter\Filters;

/**
 * Builds Font objects from font dictionaries.
 *
 * Nothing here is allowed to fail a page: a font that cannot be read comes back as a font that
 * decodes with the best table available, flagged as unmapped when there is no table at all.
 */
final class FontLoader
{
    /** Helvetica widths for codes 32-126, used when a standard font carries no /Widths. */
    private const HELVETICA = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556,
        556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];
    private const TIMES = [
        250, 333, 408, 500, 500, 833, 778, 180, 333, 333, 500, 564, 250, 333, 250, 278, 500, 500, 500, 500, 500, 500, 500, 500,
        500, 500, 278, 278, 564, 564, 564, 444, 921, 722, 667, 667, 722, 611, 556, 722, 722, 333, 389, 722, 611, 889, 722, 722,
        556, 722, 667, 556, 611, 722, 722, 944, 722, 722, 611, 333, 278, 333, 469, 500, 333, 444, 500, 444, 500, 444, 333, 500,
        500, 278, 278, 500, 278, 778, 500, 500, 500, 500, 333, 389, 278, 500, 500, 722, 500, 500, 444, 480, 200, 480, 541,
    ];

    /** TeX OT1 text encoding, the codes that differ from ASCII. */
    private const OT1 = [
        11 => "ff", 12 => "fi", 13 => "fl", 14 => "ffi", 15 => "ffl", 16 => "ı", 25 => "ß", 26 => "æ",
        27 => "œ", 28 => "ø", 29 => "Æ", 30 => "Œ", 31 => "Ø", 34 => "”", 92 => "“",
        123 => "–", 124 => "—",
    ];

    /** TeX T1 (Cork) encoding, codes 0x80-0xBF; 0xC0-0xFF follow Latin-1 with four exceptions. */
    private const T1_80 = 'ĂĄĆČĎĚĘĞĹĽŁŃŇŊŐŔŘŚŠŞŤŢŰŮŸŹŽŻĲİđ§ăąćčďěęğĺľłńňŋőŕřśšşťţűůÿźžżĳ¡¿£';

    /** @return array<int, string> */
    private static function t1(): array
    {
        $t = [
            16 => "\u{201C}", 17 => "\u{201D}", 18 => "\u{201E}", 19 => "\u{00AB}", 20 => "\u{00BB}", 21 => "\u{2013}",
            22 => "\u{2014}", 25 => "\u{0131}", 27 => 'ff', 28 => 'fi', 29 => 'fl', 30 => 'ffi', 31 => 'ffl',
        ];
        foreach (preg_split('//u', self::T1_80, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $char) {
            $t[0x80 + $i] = $char;
        }
        for ($code = 0xC0; $code <= 0xFF; $code++) {
            $t[$code] = Utf::chr($code);
        }
        $t[0xD7] = "\u{0152}";
        $t[0xF7] = "\u{0153}";
        $t[0xDF] = 'SS';
        $t[0xFF] = "\u{00DF}";
        return $t;
    }

    /** A /W range at least this wide is kept as a range. Real fonts that spell out wide ranges are rare. */
    private const WIDE_RANGE = 64;

    private const UNICODE_CMAPS = '/^\/Uni(?:GB|CNS|JIS|JISX0213|JISX02132004|JIS2004|JISPro|KS)-(?:UCS2|UTF16)(?:-HW)?-[HV]$/';
    private const CHARSETS = [
        '90ms-RKSJ' => 'SJIS-win', '90msp-RKSJ' => 'SJIS-win', '90pv-RKSJ' => 'SJIS-win', '83pv-RKSJ' => 'SJIS-win',
        'RKSJ' => 'SJIS-win', 'EUC' => 'EUC-JP', 'GBK-EUC' => 'CP936', 'GBKp-EUC' => 'CP936', 'GBK2K' => 'GB18030',
        'GB-EUC' => 'EUC-CN', 'GBpc-EUC' => 'EUC-CN', 'ETen-B5' => 'BIG-5', 'ETenms-B5' => 'BIG-5', 'B5pc' => 'BIG-5',
        'HKscs-B5' => 'BIG-5', 'KSC-EUC' => 'EUC-KR', 'KSCms-UHC' => 'UHC', 'KSCms-UHC-HW' => 'UHC', 'KSCpc-EUC' => 'EUC-KR',
    ];

    /** @var array<int|string, Font> */
    private array $cache = [];

    public function __construct(private readonly File $file)
    {
    }

    public function load(mixed $ref): Font
    {
        $key = $ref instanceof Ref ? $ref->num : 'd' . md5(serialize($ref));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        try {
            $dict = $this->file->dict($ref) ?? [];
            $font = ($dict['Subtype'] ?? null) === '/Type0' ? $this->composite($dict) : $this->simple($dict);
            if ($font->unmapped) {
                $name = $this->name($dict['BaseFont'] ?? null) ?: 'unnamed';
                $this->file->warn("Font $name has no character map; text set in it may be missing or wrong");
            }
        } catch (\Throwable $e) {
            $this->file->warn('Font could not be read (' . $e->getMessage() . '); using a default encoding');
            $font = new Font();
            $font->map = self::strtrMap(Encodings::table('WinAnsiEncoding'));
        }
        return $this->cache[$key] = $font;
    }

    /** A name such as /Helvetica without its slash. Some writers store these as strings. */
    private function name(mixed $v): string
    {
        $v = $this->file->resolve($v);
        if ($v instanceof PdfString) {
            return $v->value;
        }
        return is_string($v) ? ltrim($v, '/') : '';
    }

    /** @param array<string, mixed> $dict */
    private function simple(array $dict): Font
    {
        $file = $this->file;
        $font = new Font();
        $subtype = $dict['Subtype'] ?? null;
        $baseFont = $this->name($dict['BaseFont'] ?? null);
        $baseFont = preg_replace('/^[A-Z]{6}\+/', '', $baseFont) ?? $baseFont;
        $descriptor = $file->dict($dict['FontDescriptor'] ?? null) ?? [];
        $symbolic = ((int)($file->resolve($descriptor['Flags'] ?? 0)) & 4) !== 0;

        $encoding = $file->resolve($dict['Encoding'] ?? null);
        $baseName = null;
        $differences = [];
        if (is_string($encoding)) {
            $baseName = substr($encoding, 1);
        } elseif (is_array($encoding)) {
            $base = $file->resolve($encoding['BaseEncoding'] ?? null);
            $baseName = is_string($base) ? substr($base, 1) : null;
            $diffs = $file->resolve($encoding['Differences'] ?? null);
            if (is_array($diffs)) {
                $code = 0;
                foreach ($diffs as $item) {
                    $item = $file->resolve($item);
                    if (is_int($item) || is_float($item)) {
                        $code = (int)$item;
                    } elseif (is_string($item)) {
                        $differences[$code++] = substr($item, 1);
                    }
                }
            }
        }

        $table = null;
        if ($baseName === null) {
            if (stripos($baseFont, 'Symbol') === 0) {
                $baseName = 'Symbol';
            } elseif (stripos($baseFont, 'ZapfDingbats') === 0 || stripos($baseFont, 'Dingbats') !== false) {
                $baseName = 'ZapfDingbats';
            } elseif ($subtype === '/TrueType') {
                $baseName = 'WinAnsiEncoding';
            } else {
                $table = $this->builtinEncoding($descriptor);
                $baseName = ($symbolic && $table === null && $differences === []) ? 'WinAnsiEncoding' : 'StandardEncoding';
            }
        }
        $table ??= Encodings::table($baseName);

        $named = 0;
        $synthetic = 0;
        $texCodes = [];
        foreach ($differences as $code => $name) {
            if ($code < 0 || $code > 255) {
                continue;
            }
            $text = GlyphList::lookup($name);
            if ($text === null) {
                // A made-up name such as "a65", "g12" or "CP". When its number is the code itself the font
                // is saying which encoding it follows: in decimal (dvips bitmap fonts) a TeX text encoding,
                // which is ASCII plus a few ligatures; in hex (Windows printer drivers) the Windows one.
                // Otherwise keep whatever the base encoding says: a wrong letter can be searched around, a missing one cannot.
                $synthetic++;
                if (preg_match('/^(?:[A-Za-z]{1,2}|cid|glyph|index)(\d+)$/', $name, $m) && (int)$m[1] === $code) {
                    $texCodes[] = $code;
                } elseif ($table[$code] === '' && preg_match('/^[A-Za-z]{1,2}([0-9A-Fa-f]{2})$/', $name, $m) && hexdec($m[1]) === $code) {
                    $table[$code] = Encodings::table('WinAnsiEncoding')[$code];
                }
                continue;
            }
            if ($text !== '') {
                $named++;
            }
            $table[$code] = $text;
        }
        if ($texCodes !== []) {
            // OT1 never goes above 127, so any higher code means the 8-bit T1 (Cork) encoding.
            $tex = max($texCodes) > 127 ? self::t1() : self::OT1;
            foreach ($texCodes as $code) {
                if (isset($tex[$code])) {
                    $table[$code] = $tex[$code];
                }
            }
        }

        $toUnicode = $this->toUnicode($dict);
        if ($toUnicode !== null) {
            for ($code = 0; $code < 256; $code++) {
                $text = $toUnicode->get($code);
                if ($text !== null && $text !== '') {
                    $table[$code] = $text;
                }
            }
        } elseif ($synthetic > 8 && $named === 0) {
            // Every glyph has a made-up name and there is no ToUnicode, so the text is a guess.
            $font->unmapped = true;
        }
        $font->defaultWidth = (float)($file->resolve($descriptor['MissingWidth'] ?? 0) ?? 0);
        $widths = $file->resolve($dict['Widths'] ?? null);
        if (is_array($widths)) {
            $first = (int)($file->resolve($dict['FirstChar'] ?? 0) ?? 0);
            foreach ($widths as $i => $width) {
                if (!is_int($width) && !is_float($width)) {
                    $width = $file->resolve($width);
                }
                $font->widths[$first + $i] = (float)$width;
            }
            // A glyph with no known character but a real width still separates what is on either side
            // of it. Most often it is the space glyph, left out of the font's character map, and
            // without this the words around it come out glued together.
            $gap = Font::GAP_WIDTH / ($subtype === '/Type3' ? $this->type3Scale($dict) : 0.001);
            foreach ($table as $code => $text) {
                if ($text === '' && ($font->widths[$code] ?? 0.0) >= $gap) {
                    $table[$code] = Font::GAP;
                    $font->gaps = true;
                }
            }
        } else {
            $metrics = stripos($baseFont, 'Times') !== false ? self::TIMES : self::HELVETICA;
            $fixed = stripos($baseFont, 'Courier') !== false;
            foreach ($metrics as $i => $width) {
                $font->widths[32 + $i] = $fixed ? 600.0 : (float)$width;
            }
            $font->defaultWidth = $fixed ? 600.0 : 500.0;
        }

        if ($subtype === '/Type3') {
            $font->scale = $this->type3Scale($dict);
        }
        $font->map = self::strtrMap($table);
        return $font;
    }

    /** @param array<string, mixed> $dict */
    private function type3Scale(array $dict): float
    {
        $matrix = $this->file->resolve($dict['FontMatrix'] ?? null);
        return (is_array($matrix) && isset($matrix[0]) && (float)$matrix[0] != 0.0) ? abs((float)$matrix[0]) : 0.001;
    }

    /** @param array<string, mixed> $dict */
    private function composite(array $dict): Font
    {
        $file = $this->file;
        $font = new Font();
        $font->simple = false;
        $font->defaultWidth = 1000.0;

        $descendants = $file->resolve($dict['DescendantFonts'] ?? null);
        $descendant = is_array($descendants) ? ($file->dict($descendants[0] ?? null) ?? []) : [];
        $dw = $file->resolve($descendant['DW'] ?? null);
        if (is_int($dw) || is_float($dw)) {
            $font->defaultWidth = (float)$dw;
        }
        $this->cidWidths($font, $file->resolve($descendant['W'] ?? null));

        $encoding = $file->resolve($dict['Encoding'] ?? null);
        if ($encoding instanceof Stream) {
            $cmap = $this->parseCMap($file->streamData($encoding) ?? '');
            $this->applyCodeLengths($font, $cmap);
        } elseif (is_string($encoding)) {
            if (preg_match(self::UNICODE_CMAPS, $encoding)) {
                $font->codesAreUnicode = true;
            } elseif ($encoding !== '/Identity-H' && $encoding !== '/Identity-V') {
                $name = preg_replace('/-[HV]$/', '', substr($encoding, 1)) ?? '';
                if (isset(self::CHARSETS[$name]) && function_exists('mb_convert_encoding')) {
                    $font->charset = self::CHARSETS[$name];
                }
            }
        }

        $toUnicode = $this->toUnicode($dict);
        if ($toUnicode !== null) {
            $font->toUnicode = $toUnicode;
            $font->charset = null;
            // Identity and the Unicode CMaps are always two bytes per code, whatever the ToUnicode CMap claims.
            if (!$encoding instanceof Stream && !$font->codesAreUnicode && !in_array($encoding, ["/Identity-H", "/Identity-V"], true)) {
                $this->applyCodeLengths($font, $toUnicode);
            }
        } elseif (in_array($this->file->resolve($dict["ToUnicode"] ?? null), ["/Identity-H", "/Identity-V"], true)) {
            $font->codesAreUnicode = true;
        } elseif (!$font->codesAreUnicode && $font->charset === null) {
            $font->toUnicode = $this->fallbackMap($dict, $descendant);
            $font->unmapped = $font->toUnicode === null;
        }
        return $font;
    }

    /**
     * Last resorts for a composite font that carries no character map of its own.
     *
     * @param array<string, mixed> $dict
     * @param array<string, mixed> $descendant
     */
    private function fallbackMap(array $dict, array $descendant): ?CodeMap
    {
        // Only Identity-H and Identity-V make the code the CID, and no other code-to-CID CMap is implemented.
        if (!in_array($this->file->resolve($dict['Encoding'] ?? null), ['/Identity-H', '/Identity-V'], true)) {
            return null;
        }
        return $this->collectionMap($descendant) ?? $this->embeddedFontMap($descendant);
    }

    /**
     * A CID of one of Adobe's CJK collections has a published character.
     *
     * @param array<string, mixed> $descendant
     */
    private function collectionMap(array $descendant): ?CodeMap
    {
        $info = $this->file->dict($descendant['CIDSystemInfo'] ?? null) ?? [];
        if ($this->name($info['Registry'] ?? null) !== 'Adobe') {
            return null;
        }
        $ordering = trim($this->name($info['Ordering'] ?? null), " \t\r\n\0");
        return CidCollection::supports($ordering) ? new CidCollection($ordering) : null;
    }

    /**
     * The character map inside an embedded TrueType or OpenType program, read backwards from glyph to code point.
     *
     * @param array<string, mixed> $descendant
     */
    private function embeddedFontMap(array $descendant): ?CodeMap
    {
        $file = $this->file;
        $descriptor = $file->dict($descendant['FontDescriptor'] ?? null) ?? [];
        $program = $file->resolve($descriptor['FontFile2'] ?? null);
        if (!$program instanceof Stream) {
            $program = $file->resolve($descriptor['FontFile3'] ?? null);
            if (!$program instanceof Stream || $file->resolve($program->dict['Subtype'] ?? null) !== '/OpenType') {
                return null;
            }
        }
        $data = $file->streamData($program);
        // An OpenType program with CFF outlines ("OTTO") maps CIDs to glyphs through the CFF charset, not by glyph id.
        if ($data === null || $data === '' || str_starts_with($data, 'OTTO')) {
            return null;
        }

        $cidToGid = null;
        $gidMap = $file->resolve($descendant['CIDToGIDMap'] ?? null);
        if ($gidMap instanceof Stream) {
            $cidToGid = $file->streamData($gidMap);
            if ($cidToGid === null || $cidToGid === '') {
                return null;
            }
        }
        $map = new TrueTypeMap($data, $cidToGid);
        return $map->isUsable() ? $map : null;
    }

    private function applyCodeLengths(Font $font, CMap $cmap): void
    {
        $lengths = array_keys($cmap->lengths);
        if ($lengths === []) {
            return;
        }
        if (count($lengths) === 1 && $lengths[0] <= 2) {
            $font->codeBytes = $lengths[0];
            return;
        }
        if ($cmap->codespaces !== []) {
            $font->codespaces = $cmap->codespaces;
        }
    }

    private function cidWidths(Font $font, mixed $w): void
    {
        if (!is_array($w)) {
            return;
        }
        $file = $this->file;
        $n = count($w);
        for ($i = 0; $i < $n;) {
            $first = $file->resolve($w[$i] ?? null);
            $second = $file->resolve($w[$i + 1] ?? null);
            if (!is_int($first) && !is_float($first)) {
                $i++;
                continue;
            }
            $first = (int)$first;
            if (is_array($second)) {
                foreach ($second as $k => $width) {
                    $font->widths[$first + $k] = (float)$file->resolve($width);
                }
                $i += 2;
                continue;
            }
            $width = (float)$file->resolve($w[$i + 2] ?? 0);
            $last = min((int)$second, $first + 65535);
            if ($last - $first >= self::WIDE_RANGE) {
                // Writing this out takes time in proportion to the range, not to the file, and a /W array can
                // hold thousands of them. Start again and keep every range as a range.
                $font->widths = [];
                $this->cidWidthRanges($font, $w);
                return;
            }
            for ($cid = $first; $cid <= $last; $cid++) {
                $font->widths[$cid] = $width;
            }
            $i += 3;
        }
    }

    /** Reads a /W array that has wide ranges in it, leaving them unexpanded. A later entry wins over an earlier one, as when expanded. */
    private function cidWidthRanges(Font $font, array $w): void
    {
        $file = $this->file;
        $n = count($w);
        $ranges = [];
        $widths = [];
        for ($i = 0; $i < $n;) {
            $first = $file->resolve($w[$i] ?? null);
            $second = $file->resolve($w[$i + 1] ?? null);
            if (!is_int($first) && !is_float($first)) {
                $i++;
                continue;
            }
            $first = (int)$first;
            if (is_array($second)) {
                foreach ($second as $k => $width) {
                    $ranges[] = [$first + $k, $first + $k];
                    $widths[] = (float)$file->resolve($width);
                }
                $i += 2;
                continue;
            }
            $ranges[] = [$first, min((int)$second, $first + 65535)];
            $widths[] = (float)$file->resolve($w[$i + 2] ?? 0);
            $i += 3;
        }
        // The index lets the earlier of two overlapping ranges win, so the list goes in last entry first.
        $font->widthIndex = new Ranges(array_reverse($ranges));
        $font->rangeWidths = array_reverse($widths);
    }

    private function parseCMap(string $data): CMap
    {
        $cmap = CMap::parse($data, $this->file->room());
        if ($cmap->partial) {
            $this->file->warn('A character map is too large to read in the memory that is left; part of it was left out');
        }
        return $cmap;
    }

    /** @param array<string, mixed> $dict */
    private function toUnicode(array $dict): ?CMap
    {
        $stream = $this->file->resolve($dict['ToUnicode'] ?? null);
        if (!$stream instanceof Stream) {
            return null;
        }
        $data = $this->file->streamData($stream);
        if ($data === null || $data === '') {
            return null;
        }
        $cmap = $this->parseCMap($data);
        // Some writers compress a ToUnicode stream and forget to declare /FlateDecode. A zlib header (0x78 and a
        // checksum that divides by 31) is unlikely to start real CMap text, so inflate it once and read again.
        if ($cmap->isEmpty() && strlen($data) > 2 && (ord($data[0]) & 0x0F) === 8 && ((ord($data[0]) << 8) | ord($data[1])) % 31 === 0) {
            $cmap = $this->parseCMap(Filters::flate($data, $this->file->limit()));
        }
        return $cmap->isEmpty() ? null : $cmap;
    }

    /**
     * The encoding array written inside an embedded Type 1 font program, for fonts whose dictionary has none.
     *
     * @param array<string, mixed> $descriptor
     * @return array<int, string>|null
     */
    private function type1BuiltinEncoding(array $descriptor): ?array
    {
        $stream = $this->file->resolve($descriptor['FontFile'] ?? null);
        if (!$stream instanceof Stream) {
            return null;
        }
        // Only the clear-text start of the program holds the encoding, so only that much is unpacked.
        $clear = (int)($this->file->resolve($stream->dict['Length1'] ?? 0) ?? 0);
        $head = $this->file->streamHead($stream, $clear > 0 ? min($clear, 1 << 20) : 8192);
        if ($head === null) {
            return null;
        }
        if (!preg_match_all('/dup\s+(\d+)\s*\/([^\s\/]+)\s+put/', $head, $m, PREG_SET_ORDER)) {
            return null;
        }
        $table = array_fill(0, 256, '');
        foreach ($m as $row) {
            $code = (int)$row[1];
            if ($code < 256) {
                $table[$code] = GlyphList::toUnicode($row[2]);
            }
        }
        return $table;
    }

    /**
     * The encoding built into an embedded font program, for fonts whose dictionary names no base encoding.
     *
     * @param array<string, mixed> $descriptor
     * @return array<int, string>|null
     */
    private function builtinEncoding(array $descriptor): ?array
    {
        return $this->type1BuiltinEncoding($descriptor) ?? $this->cffBuiltinEncoding($descriptor);
    }

    /**
     * The built-in encoding of an embedded CFF font program (/FontFile3, Type1C or OpenType).
     *
     * @param array<string, mixed> $descriptor
     * @return array<int, string>|null
     */
    private function cffBuiltinEncoding(array $descriptor): ?array
    {
        $stream = $this->file->resolve($descriptor['FontFile3'] ?? null);
        if (!$stream instanceof Stream || !in_array($this->file->resolve($stream->dict['Subtype'] ?? null), ['/Type1C', '/OpenType'], true)) {
            return null;
        }
        $data = $this->file->streamData($stream);
        $names = $data === null ? null : Cff::encoding($data);
        if ($names === null) {
            return null;
        }
        // Codes the font does not list, and glyph names we cannot read ("g12", "C85"), keep StandardEncoding's
        // character, the same rule the Differences array follows: a wrong letter can be searched around, a missing one cannot.
        $table = Encodings::table('StandardEncoding');
        foreach ($names as $code => $name) {
            $table[$code] = GlyphList::lookup($name) ?? $table[$code];
        }
        return $table;
    }

    /**
     * @param array<int, string> $table
     * @return array<string, string>
     */
    private static function strtrMap(array $table): array
    {
        $map = [];
        for ($i = 0; $i < 256; $i++) {
            $byte = chr($i);
            $text = $table[$i] ?? '';
            // strtr() already leaves bytes without a replacement unchanged.
            if ($text !== $byte) {
                $map[$byte] = $text;
            }
        }
        return $map;
    }
}
