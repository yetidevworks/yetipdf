<?php

declare(strict_types=1);

namespace YetiPdf\Font;

/**
 * Reads the built-in encoding out of a CFF font program (the Type1C kind embedded in PDFs).
 *
 * That is all it does: no charstrings, no widths, no CID-keyed fonts. Every offset and count in the
 * font is untrusted, so each read is checked against the data and anything out of range ends the
 * parse with null. Work is bounded by the font's own size: no loop runs more than a few hundred times.
 */
final class Cff
{
    /** The 391 standard strings of Adobe Technical Note 5176, Appendix A, in SID order. None contains a space. */
    private const STANDARD_STRINGS = <<<'NAMES'
        .notdef space exclam quotedbl numbersign dollar percent ampersand quoteright parenleft parenright asterisk plus
        comma hyphen period slash zero one two three four five six seven eight nine colon semicolon less equal greater
        question at A B C D E F G H I J K L M N O P Q R S T U V W X Y Z bracketleft backslash bracketright asciicircum
        underscore quoteleft a b c d e f g h i j k l m n o p q r s t u v w x y z braceleft bar braceright asciitilde
        exclamdown cent sterling fraction yen florin section currency quotesingle quotedblleft guillemotleft
        guilsinglleft guilsinglright fi fl endash dagger daggerdbl periodcentered paragraph bullet quotesinglbase
        quotedblbase quotedblright guillemotright ellipsis perthousand questiondown grave acute circumflex tilde macron
        breve dotaccent dieresis ring cedilla hungarumlaut ogonek caron emdash AE ordfeminine Lslash Oslash OE
        ordmasculine ae dotlessi lslash oslash oe germandbls onesuperior logicalnot mu trademark Eth onehalf plusminus
        Thorn onequarter divide brokenbar degree thorn threequarters twosuperior registered minus eth multiply
        threesuperior copyright Aacute Acircumflex Adieresis Agrave Aring Atilde Ccedilla Eacute Ecircumflex Edieresis
        Egrave Iacute Icircumflex Idieresis Igrave Ntilde Oacute Ocircumflex Odieresis Ograve Otilde Scaron Uacute
        Ucircumflex Udieresis Ugrave Yacute Ydieresis Zcaron aacute acircumflex adieresis agrave aring atilde ccedilla
        eacute ecircumflex edieresis egrave iacute icircumflex idieresis igrave ntilde oacute ocircumflex odieresis
        ograve otilde scaron uacute ucircumflex udieresis ugrave yacute ydieresis zcaron exclamsmall Hungarumlautsmall
        dollaroldstyle dollarsuperior ampersandsmall Acutesmall parenleftsuperior parenrightsuperior twodotenleader
        onedotenleader zerooldstyle oneoldstyle twooldstyle threeoldstyle fouroldstyle fiveoldstyle sixoldstyle
        sevenoldstyle eightoldstyle nineoldstyle commasuperior threequartersemdash periodsuperior questionsmall
        asuperior bsuperior centsuperior dsuperior esuperior isuperior lsuperior msuperior nsuperior osuperior rsuperior
        ssuperior tsuperior ff ffi ffl parenleftinferior parenrightinferior Circumflexsmall hyphensuperior Gravesmall
        Asmall Bsmall Csmall Dsmall Esmall Fsmall Gsmall Hsmall Ismall Jsmall Ksmall Lsmall Msmall Nsmall Osmall Psmall
        Qsmall Rsmall Ssmall Tsmall Usmall Vsmall Wsmall Xsmall Ysmall Zsmall colonmonetary onefitted rupiah Tildesmall
        exclamdownsmall centoldstyle Lslashsmall Scaronsmall Zcaronsmall Dieresissmall Brevesmall Caronsmall
        Dotaccentsmall Macronsmall figuredash hypheninferior Ogoneksmall Ringsmall Cedillasmall questiondownsmall
        oneeighth threeeighths fiveeighths seveneighths onethird twothirds zerosuperior foursuperior fivesuperior
        sixsuperior sevensuperior eightsuperior ninesuperior zeroinferior oneinferior twoinferior threeinferior
        fourinferior fiveinferior sixinferior seveninferior eightinferior nineinferior centinferior dollarinferior
        periodinferior commainferior Agravesmall Aacutesmall Acircumflexsmall Atildesmall Adieresissmall Aringsmall
        AEsmall Ccedillasmall Egravesmall Eacutesmall Ecircumflexsmall Edieresissmall Igravesmall Iacutesmall
        Icircumflexsmall Idieresissmall Ethsmall Ntildesmall Ogravesmall Oacutesmall Ocircumflexsmall Otildesmall
        Odieresissmall OEsmall Oslashsmall Ugravesmall Uacutesmall Ucircumflexsmall Udieresissmall Yacutesmall
        Thornsmall Ydieresissmall 001.000 001.001 001.002 001.003 Black Bold Book Light Medium Regular Roman Semibold
NAMES;

    /** @var list<string>|null */
    private static ?array $standard = null;

    private readonly int $length;

    private function __construct(private readonly string $data)
    {
        $this->length = strlen($data);
    }

    /**
     * The font's own encoding as code => glyph name, or null when it has none we can use: the font
     * uses StandardEncoding or an Expert set, is CID-keyed, is not CFF, or is damaged.
     *
     * @param string $data a CFF font program, or an OpenType file wrapping one
     * @return array<int, string>|null
     */
    public static function encoding(string $data): ?array
    {
        try {
            $cff = new self($data);
            if (str_starts_with($data, 'OTTO')) {
                $cff = $cff->sfntTable('CFF ');
            }
            return $cff->parse();
        } catch (\OutOfRangeException) {
            return null;
        }
    }

    /** The name of a standard string (SID 0 to 390), or null for any other number. */
    public static function standardString(int $sid): ?string
    {
        self::$standard ??= preg_split('/\s+/', trim(self::STANDARD_STRINGS)) ?: [];
        return self::$standard[$sid] ?? null;
    }

    /** @return array<int, string>|null */
    private function parse(): ?array
    {
        if ($this->uint(0, 1) !== 1) {
            return null;
        }
        $headerSize = $this->uint(2, 1);
        if ($headerSize < 4) {
            return null;
        }
        $names = $this->index($headerSize);
        $top = $this->index($names['end']);
        if ($top['count'] < 1) {
            return null;
        }
        $strings = $this->index($top['end']);

        $dict = $this->dict($this->item($top, 0));
        if (isset($dict[1230])) {
            // ROS marks a CID-keyed font, which has no code-to-glyph encoding.
            return null;
        }
        $encodingAt = (int)($dict[16] ?? 0);
        $charsetAt = (int)($dict[15] ?? 0);
        if ($encodingAt < 2 || $charsetAt === 1 || $charsetAt === 2) {
            // 0 is StandardEncoding, which the caller already assumes; 1 is Expert, and so are charsets 1 and 2.
            return null;
        }

        [$glyphs, $supplement] = $this->encodingTable($encodingAt);
        $sids = $this->charset($charsetAt, $glyphs === [] ? 0 : max($glyphs));
        $table = [];
        foreach ($glyphs as $code => $gid) {
            $name = isset($sids[$gid]) ? $this->glyphName($sids[$gid], $strings) : null;
            if ($name !== null) {
                $table[$code] = $name;
            }
        }
        foreach ($supplement as $code => $sid) {
            $name = $this->glyphName($sid, $strings);
            if ($name !== null) {
                $table[$code] = $name;
            }
        }
        return $table === [] ? null : $table;
    }

    /**
     * The Encoding structure at an offset: code => glyph id, and code => SID for the supplement.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function encodingTable(int $at): array
    {
        $format = $this->uint($at, 1);
        $count = $this->uint($at + 1, 1);
        $glyphs = [];
        if (($format & 0x7F) === 0) {
            for ($i = 0; $i < $count; $i++) {
                $glyphs[$this->uint($at + 2 + $i, 1)] = $i + 1;
            }
            $end = $at + 2 + $count;
        } elseif (($format & 0x7F) === 1) {
            $gid = 1;
            for ($i = 0; $i < $count; $i++) {
                $first = $this->uint($at + 2 + 2 * $i, 1);
                $left = $this->uint($at + 3 + 2 * $i, 1);
                for ($code = $first; $code <= $first + $left; $code++, $gid++) {
                    if ($code > 255 || $gid > 255) {
                        throw new \OutOfRangeException('Encoding names more than 255 glyphs');
                    }
                    $glyphs[$code] = $gid;
                }
            }
            $end = $at + 2 + 2 * $count;
        } else {
            throw new \OutOfRangeException('Unknown encoding format');
        }

        $supplement = [];
        if (($format & 0x80) !== 0) {
            $extra = $this->uint($end, 1);
            for ($i = 0; $i < $extra; $i++) {
                $supplement[$this->uint($end + 1 + 3 * $i, 1)] = $this->uint($end + 2 + 3 * $i, 2);
            }
        }
        return [$glyphs, $supplement];
    }

    /**
     * Glyph id => SID for glyphs 1 to $last. Glyph 0 is always .notdef, and is left out.
     *
     * @return array<int, int>
     */
    private function charset(int $at, int $last): array
    {
        $sids = [];
        if ($at === 0) {
            // ISOAdobe: the first 228 glyphs are the standard strings in order.
            for ($gid = 1; $gid <= min($last, 228); $gid++) {
                $sids[$gid] = $gid;
            }
            return $sids;
        }
        $format = $this->uint($at, 1);
        $p = $at + 1;
        $gid = 1;
        if ($format === 0) {
            for (; $gid <= $last; $gid++, $p += 2) {
                $sids[$gid] = $this->uint($p, 2);
            }
        } elseif ($format === 1 || $format === 2) {
            $size = $format === 1 ? 1 : 2;
            while ($gid <= $last) {
                $first = $this->uint($p, 2);
                $left = $this->uint($p + 2, $size);
                $p += 2 + $size;
                for ($i = 0; $i <= $left && $gid <= $last; $i++, $gid++) {
                    $sids[$gid] = $first + $i;
                }
            }
        } else {
            throw new \OutOfRangeException('Unknown charset format');
        }
        return $sids;
    }

    /** @param array{count: int, size: int, offsets: int, base: int, last: int, end: int} $strings */
    private function glyphName(int $sid, array $strings): ?string
    {
        if ($sid < 391) {
            return self::standardString($sid);
        }
        $i = $sid - 391;
        // A SID with no string behind it is a broken font; its glyph just has no name.
        return $i < $strings['count'] ? $this->item($strings, $i) : null;
    }

    /**
     * An INDEX: its count, and where its offset array and data sit. Nothing is copied, items are read on demand.
     *
     * @return array{count: int, size: int, offsets: int, base: int, last: int, end: int}
     */
    private function index(int $at): array
    {
        $count = $this->uint($at, 2);
        if ($count === 0) {
            return ['count' => 0, 'size' => 1, 'offsets' => $at + 2, 'base' => $at + 1, 'last' => 1, 'end' => $at + 2];
        }
        $size = $this->uint($at + 2, 1);
        if ($size < 1 || $size > 4) {
            throw new \OutOfRangeException('Bad offset size');
        }
        $offsets = $at + 3;
        // Offsets count from the byte before the data, and the last one is the end of the data.
        $base = $offsets + ($count + 1) * $size - 1;
        $last = $this->uint($offsets + $count * $size, $size);
        if ($last < 1 || $base + $last > $this->length) {
            throw new \OutOfRangeException('INDEX runs past the end');
        }
        return ['count' => $count, 'size' => $size, 'offsets' => $offsets, 'base' => $base, 'last' => $last, 'end' => $base + $last];
    }

    /** @param array{count: int, size: int, offsets: int, base: int, last: int, end: int} $index */
    private function item(array $index, int $i): string
    {
        $from = $this->uint($index['offsets'] + $i * $index['size'], $index['size']);
        $to = $this->uint($index['offsets'] + ($i + 1) * $index['size'], $index['size']);
        if ($from < 1 || $to < $from || $to > $index['last']) {
            throw new \OutOfRangeException('Bad INDEX offsets');
        }
        return substr($this->data, $index['base'] + $from, $to - $from);
    }

    /**
     * A DICT as operator => its last operand. A two-byte operator "12 n" is stored as 1200 + n.
     *
     * @return array<int, int|float>
     */
    private function dict(string $s): array
    {
        $n = strlen($s);
        $out = [];
        $last = 0;
        for ($i = 0; $i < $n;) {
            $b = ord($s[$i++]);
            if ($b >= 32 && $b <= 246) {
                $last = $b - 139;
            } elseif ($b >= 247 && $b <= 254) {
                if ($i >= $n) {
                    throw new \OutOfRangeException('DICT ends inside a number');
                }
                $b1 = ord($s[$i++]);
                $last = $b <= 250 ? ($b - 247) * 256 + $b1 + 108 : -($b - 251) * 256 - $b1 - 108;
            } elseif ($b === 28 || $b === 29) {
                $size = $b === 28 ? 2 : 4;
                if ($i + $size > $n) {
                    throw new \OutOfRangeException('DICT ends inside a number');
                }
                $value = 0;
                for ($k = 0; $k < $size; $k++) {
                    $value = ($value << 8) | ord($s[$i++]);
                }
                $last = $value >= (1 << ($size * 8 - 1)) ? $value - (1 << ($size * 8)) : $value;
            } elseif ($b === 30) {
                // A real number: two digits per byte until a byte holds the end marker 0xF. None of the
                // values we want is real, so it is skipped.
                do {
                    if ($i >= $n) {
                        throw new \OutOfRangeException('DICT ends inside a number');
                    }
                    $byte = ord($s[$i++]);
                } while (($byte & 0x0F) !== 0x0F && ($byte >> 4) !== 0x0F);
                $last = 0;
            } elseif ($b <= 21) {
                if ($b === 12) {
                    if ($i >= $n) {
                        throw new \OutOfRangeException('DICT ends inside an operator');
                    }
                    $b = 1200 + ord($s[$i++]);
                }
                $out[$b] = $last;
                $last = 0;
            } else {
                throw new \OutOfRangeException('Reserved DICT byte');
            }
        }
        return $out;
    }

    /** The CFF table of an OpenType file, as a reader of its own. */
    private function sfntTable(string $tag): self
    {
        $tables = $this->uint(4, 2);
        for ($i = 0; $i < $tables; $i++) {
            $record = 12 + 16 * $i;
            $size = $this->uint($record + 12, 4);
            if (substr($this->data, $record, 4) === $tag) {
                $offset = $this->uint($record + 8, 4);
                if ($offset + $size > $this->length) {
                    throw new \OutOfRangeException('Table runs past the end');
                }
                return new self(substr($this->data, $offset, $size));
            }
        }
        throw new \OutOfRangeException('No CFF table');
    }

    /** An unsigned big-endian integer of 1 to 4 bytes, read only when all its bytes are there. */
    private function uint(int $at, int $size): int
    {
        if ($at < 0 || $at + $size > $this->length) {
            throw new \OutOfRangeException('Read past the end of the font');
        }
        $value = 0;
        for ($i = 0; $i < $size; $i++) {
            $value = ($value << 8) | ord($this->data[$at + $i]);
        }
        return $value;
    }
}
