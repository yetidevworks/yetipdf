<?php

declare(strict_types=1);

namespace YetiPdf\Font;

/**
 * Character codes to text for a composite font that has no ToUnicode CMap, read from the font
 * program itself.
 *
 * A TrueType or OpenType program carries a cmap table that maps Unicode to glyph ids. This class
 * runs that table backwards: the glyph a code selects is the glyph id (or the CIDToGIDMap entry
 * for the code), and the text is the code point the table lists for that glyph.
 *
 * Only the sfnt table directory and the cmap table are read. Nothing is parsed until the first
 * get() or isUsable(). A glyph that the cmap reaches only through a Private Use Area code point
 * has no text, and a font where that is true of most glyphs gets no map at all.
 *
 * Every offset and count in the program is untrusted, so reads are bounds checked, loops are
 * capped by the 65,536 glyph ids a font can have, and anything unreadable means "no map".
 */
final class TrueTypeMap implements CodeMap
{
    private const GLYPH_IDS = 65536;
    /** Upper limit on code points walked in total, so a table that claims billions of them costs the same as a real one. */
    private const STEP_LIMIT = 262144;
    /** Added to a code point in a Private Use Area so any ordinary code point sorts before it. */
    private const PRIVATE = 0x1000000;

    /** The glyph id => code point table, 3 bytes per glyph id; null until first needed. */
    private ?string $glyphs = null;
    /** @var array{0: int, 1: int, 2: bool}|false|null [format, offset of the subtable, symbol font]; false when none is usable */
    private array|false|null $subtable = null;

    /**
     * @param string $font the bytes of the sfnt font program
     * @param string|null $cidToGid the CIDToGIDMap stream, two big-endian bytes per CID; null for glyph id = CID
     */
    public function __construct(private string $font, private readonly ?string $cidToGid = null)
    {
    }

    /** Whether the program's cmap gives text for at least some glyphs. This reads the table, so it is the one call that does the work before get(). */
    public function isUsable(): bool
    {
        return $this->locate() !== false && ($this->glyphs ??= $this->build()) !== '';
    }

    public function get(int $code): ?string
    {
        $glyphs = $this->glyphs ??= $this->build();
        $gid = $code;
        if ($this->cidToGid !== null) {
            $at = $code * 2;
            if ($code < 0 || $at + 2 > strlen($this->cidToGid)) {
                return null;
            }
            $gid = (ord($this->cidToGid[$at]) << 8) | ord($this->cidToGid[$at + 1]);
        }
        $at = $gid * 3;
        if ($gid <= 0 || $at + 3 > strlen($glyphs)) {
            return null;
        }
        $cp = (ord($glyphs[$at]) << 16) | (ord($glyphs[$at + 1]) << 8) | ord($glyphs[$at + 2]);
        return $cp === 0 ? null : Utf::chr($cp);
    }

    private function u16(int $p): int
    {
        return (ord($this->font[$p] ?? "\0") << 8) | ord($this->font[$p + 1] ?? "\0");
    }

    private function u32(int $p): int
    {
        return ($this->u16($p) << 16) | $this->u16($p + 2);
    }

    /** @return array{0: int, 1: int, 2: bool}|false */
    private function locate(): array|false
    {
        if ($this->subtable !== null) {
            return $this->subtable;
        }
        $this->subtable = false;
        $d = $this->font;
        $size = strlen($d);
        if ($size < 12 || !in_array(substr($d, 0, 4), ["\0\1\0\0", 'true', 'OTTO'], true)) {
            return false;
        }

        $cmap = -1;
        $tables = min($this->u16(4), intdiv($size - 12, 16));
        for ($i = 0; $i < $tables; $i++) {
            if (substr_compare($d, 'cmap', 12 + $i * 16, 4) === 0) {
                $cmap = $this->u32(12 + $i * 16 + 8);
                break;
            }
        }
        if ($cmap < 0 || $cmap + 4 > $size) {
            return false;
        }

        // Lower rank is better; the order is the order of preference for choosing between subtables.
        $best = 99;
        $count = min($this->u16($cmap + 2), intdiv($size - $cmap - 4, 8));
        for ($i = 0; $i < $count; $i++) {
            $record = $cmap + 4 + $i * 8;
            $platform = $this->u16($record);
            $encoding = $this->u16($record + 2);
            $offset = $cmap + $this->u32($record + 4);
            if ($offset + 4 > $size) {
                continue;
            }
            $format = $this->u16($offset);
            $rank = match (true) {
                $platform === 3 && $encoding === 10 && $format === 12 => 0,
                $platform === 0 && $format === 12 => 1,
                $platform === 0 && $format === 4 => 2,
                $platform === 3 && $encoding === 1 && $format === 4 => 3,
                $platform === 3 && $encoding === 0 && $format === 4 => 4,
                $platform === 1 && $encoding === 0 && ($format === 0 || $format === 6) => 5,
                default => 99,
            };
            if ($rank < $best) {
                $best = $rank;
                $this->subtable = [$format, $offset, $platform === 3 && $encoding === 0];
            }
        }
        return $this->subtable;
    }

    /** Glyph id => code point, packed three bytes per glyph id. The font program is let go afterwards: it can be megabytes. */
    private function build(): string
    {
        $glyphs = $this->read();
        $this->font = '';
        return $glyphs;
    }

    private function read(): string
    {
        $subtable = $this->locate();
        if ($subtable === false) {
            return '';
        }
        [$format, $at, $symbol] = $subtable;
        /** @var array<int, int> $found glyph id => code point, with Private Use Area code points flagged by PRIVATE */
        $found = [];
        $steps = self::STEP_LIMIT;

        if ($format === 12) {
            $groups = min($this->u32($at + 12), intdiv(strlen($this->font) - $at - 16, 12));
            for ($g = 0; $g < $groups && $steps > 0; $g++) {
                $row = unpack('Nfirst/Nlast/Ngid', $this->font, $at + 16 + $g * 12);
                $steps--;
                if ($row === false || $row['last'] < $row['first'] || $row['first'] > 0x10FFFF || $row['gid'] >= self::GLYPH_IDS) {
                    continue;
                }
                $n = min($row['last'] - $row['first'], 0x10FFFF - $row['first'], self::GLYPH_IDS - 1 - $row['gid'], $steps - 1) + 1;
                for ($i = 0; $i < $n; $i++) {
                    self::put($found, $row['gid'] + $i, $row['first'] + $i);
                }
                $steps -= $n;
            }
        } elseif ($format === 4) {
            $this->format4($found, $at, $symbol, $steps);
        } elseif ($format === 6) {
            $first = $this->u16($at + 6);
            $n = min($this->u16($at + 8), intdiv(strlen($this->font) - $at - 10, 2));
            $ids = $n > 0 ? unpack('n*', substr($this->font, $at + 10, $n * 2)) : false;
            $this->macEntries($found, $first, is_array($ids) ? $ids : []);
        } elseif ($format === 0 && strlen($this->font) >= $at + 262) {
            $this->macEntries($found, 0, array_values(unpack('C256', $this->font, $at + 6) ?: []));
        }

        // A glyph whose only code point is in a Private Use Area has no text to give. When most glyphs are like that,
        // the font is custom-encoded (Arabic glyph variants are the usual case) and none of its code points can be trusted.
        $private = 0;
        foreach ($found as $cp) {
            $private += $cp >= self::PRIVATE ? 1 : 0;
        }
        if ($found === [] || $private * 2 > count($found)) {
            return '';
        }
        $out = str_repeat("\0\0\0", max(array_keys($found)) + 1);
        foreach ($found as $gid => $cp) {
            if ($cp >= self::PRIVATE) {
                continue;
            }
            $out[$gid * 3] = chr($cp >> 16);
            $out[$gid * 3 + 1] = chr(($cp >> 8) & 0xFF);
            $out[$gid * 3 + 2] = chr($cp & 0xFF);
        }
        return $out;
    }

    /** @param array<int, int> $found */
    private function format4(array &$found, int $at, bool $symbol, int &$steps): void
    {
        $size = strlen($this->font);
        $segments = min($this->u16($at + 6) >> 1, intdiv($size - $at - 16, 8));
        if ($segments < 1) {
            return;
        }
        $width = $segments * 2;
        $endAt = $at + 14;
        $startAt = $endAt + $width + 2;
        $deltaAt = $startAt + $width;
        $offsetAt = $deltaAt + $width;
        $ends = unpack('n*', substr($this->font, $endAt, $width));
        $starts = unpack('n*', substr($this->font, $startAt, $width));
        $deltas = unpack('n*', substr($this->font, $deltaAt, $width));
        $offsets = unpack('n*', substr($this->font, $offsetAt, $width));
        if ($ends === false || $starts === false || $deltas === false || $offsets === false) {
            return;
        }

        for ($s = 1; $s <= $segments && $steps > 0; $s++) {
            $steps--;
            $first = $starts[$s] ?? 0xFFFF;
            // Code points above 0xFFFD are noncharacters, and the final segment is only there to end the table.
            $last = min($ends[$s] ?? 0, 0xFFFD);
            if ($last < $first) {
                continue;
            }
            $n = min($last - $first + 1, $steps);
            $delta = $deltas[$s] ?? 0;
            $range = $offsets[$s] ?? 0;
            if ($range === 0) {
                for ($i = 0; $i < $n; $i++) {
                    self::put($found, ($first + $i + $delta) & 0xFFFF, self::fold($first + $i, $symbol));
                }
            } else {
                $from = $offsetAt + ($s - 1) * 2 + $range;
                $n = min($n, intdiv($size - $from, 2));
                $ids = $n > 0 ? unpack('n*', substr($this->font, $from, $n * 2)) : false;
                for ($i = 0; $ids !== false && $i < $n; $i++) {
                    $id = $ids[$i + 1] ?? 0;
                    if ($id !== 0) {
                        self::put($found, ($id + $delta) & 0xFFFF, self::fold($first + $i, $symbol));
                    }
                }
            }
            $steps -= $n;
        }
    }

    /**
     * Mac Roman subtables (formats 0 and 6): codes are single bytes, and the encoding table says which text each one is.
     *
     * @param array<int, int> $found
     * @param array<int, int> $ids glyph ids for consecutive codes starting at $first
     */
    private function macEntries(array &$found, int $first, array $ids): void
    {
        $table = Encodings::table('MacRomanEncoding');
        foreach (array_values($ids) as $i => $gid) {
            $text = $table[$first + $i] ?? '';
            if ($gid === 0 || $text === '') {
                continue;
            }
            $b = ord($text[0]);
            $cp = match (true) {
                $b < 0x80 => $b,
                $b < 0xE0 => (($b & 0x1F) << 6) | (ord($text[1]) & 0x3F),
                $b < 0xF0 => (($b & 0x0F) << 12) | ((ord($text[1]) & 0x3F) << 6) | (ord($text[2]) & 0x3F),
                default => 0,
            };
            self::put($found, $gid, $cp);
        }
    }

    /** Symbol fonts keep their characters at U+F020..U+F0FF; the text they mean is at U+0020..U+00FF. */
    private static function fold(int $cp, bool $symbol): int
    {
        return ($symbol && $cp >= 0xF020 && $cp <= 0xF0FF) ? $cp - 0xF000 : $cp;
    }

    /**
     * Records that a glyph is drawn for a code point, keeping the best code point when several share the glyph:
     * an ordinary one over one in a Private Use Area, and then the lowest.
     *
     * @param array<int, int> $found
     */
    private static function put(array &$found, int $gid, int $cp): void
    {
        // U+0000 and U+FFFD are placeholders, and control characters are not text a glyph stands for.
        if ($gid <= 0 || $gid >= self::GLYPH_IDS || $cp < 0x20 || ($cp >= 0x7F && $cp <= 0x9F) || $cp === 0xFFFD || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return;
        }
        if (($cp >= 0xE000 && $cp <= 0xF8FF) || $cp >= 0xF0000) {
            $cp |= self::PRIVATE;
        }
        if (!isset($found[$gid]) || $cp < $found[$gid]) {
            $found[$gid] = $cp;
        }
    }
}
