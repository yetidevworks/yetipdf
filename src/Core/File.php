<?php

declare(strict_types=1);

namespace YetiPdf\Core;

use YetiPdf\Crypt\Decryptor;
use YetiPdf\Exception\InvalidPdfException;
use YetiPdf\Filter\Filters;

/**
 * The file-level view of a PDF: the cross-reference table, lazy object loading, and stream decoding.
 *
 * Objects are parsed only when something asks for them. If the cross-reference data is missing or
 * wrong, the whole file is scanned for "N G obj" markers and the table is rebuilt from what is found.
 */
final class File
{
    private const CACHE_LIMIT = 1024;
    /** Decoded object streams kept at once; the oldest is dropped to make room. */
    private const OBJSTM_LIMIT = 32;
    /**
     * Smallest and largest size a decoded stream may reach when the limit comes from the memory that is left.
     * The smallest is low on purpose: with 20MB left, a promise of 16MB is the thing that would run out.
     */
    private const MIN_DECODED = 4 << 20;
    private const MAX_DECODED = 512 << 20;
    /** Warnings kept; a file full of broken parts would otherwise fill memory with notes about them. */
    private const WARNING_LIMIT = 100;

    /**
     * Object number => where to find it, packed into one integer because a large PDF has tens of
     * thousands of these: (file offset << 1) for an object in the file itself, or
     * (container object number << 1 | 1) for one stored inside an object stream.
     *
     * @var array<int, int>
     */
    private array $xref = [];
    /** @var array<string, mixed> */
    private array $trailer = [];
    /** @var array<int, mixed> */
    private array $cache = [];
    /** @var array<int, array{0: string, 1: array<int, int>}> container number => [decoded data, object number => offset] */
    private array $objStreams = [];
    private bool $rebuilt = false;
    /** @var list<int> object streams found while rebuilding */
    private array $containers = [];
    private ?Decryptor $crypt = null;
    /** Where the last search for "endstream" came up empty, so later streams do not search the same stretch again. */
    private int $noEndstream = PHP_INT_MAX;
    /** @var array<string, true> */
    private array $noted = [];
    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param int|null $memory bytes this document may use for decoded streams and page content; null means
     *        whatever is left under PHP's memory_limit. Tests pass a small number to see the limits work.
     */
    public function __construct(private readonly string $data, string $password = '', private readonly ?int $memory = null)
    {
        if (!str_contains(substr($data, 0, 1024), '%PDF-') && !str_contains($data, ' obj')) {
            throw new InvalidPdfException('Not a PDF file');
        }

        try {
            $this->readXref();
        } catch (\Throwable) {
            $this->xref = [];
        }
        if ($this->xref === [] || !isset($this->trailer['Root'])) {
            $this->rebuild();
        }
        if (!isset($this->trailer['Root'])) {
            throw new InvalidPdfException('No document catalog found');
        }

        if (isset($this->trailer['Encrypt'])) {
            $encrypt = $this->resolve($this->trailer['Encrypt']);
            if (is_array($encrypt)) {
                $id = $this->resolve($this->trailer['ID'] ?? null);
                $first = is_array($id) ? $this->resolve($id[0] ?? null) : null;
                $resolved = [];
                foreach ($encrypt as $k => $v) {
                    $resolved[$k] = $this->resolve($v);
                }
                // The encryption dictionary's own strings are never encrypted, so it is read before the handler exists.
                $this->crypt = new Decryptor($resolved, $first instanceof PdfString ? $first->value : '', $password);
                $this->cache = [];
                $this->objStreams = [];
                // Object streams are encrypted too, so a rebuilt table could not see inside them until now.
                $this->indexContainers();
            }
        }

        // A table can be damaged and still hold entries, only not one for the catalog. Nothing after this
        // point would find a page, so the file is scanned now.
        if (!$this->rebuilt && !is_array($this->resolve($this->trailer['Root']))) {
            $this->rebuild();
        }
    }

    /** @return array<string, mixed> */
    public function trailer(): array
    {
        return $this->trailer;
    }

    public function isEncrypted(): bool
    {
        return $this->crypt !== null;
    }

    /**
     * Adds a note to $warnings unless it is already there. $key decides what counts as the same note: by
     * default the message itself, so a note whose text varies (a changing size, say) can still be kept once.
     */
    public function warn(string $message, ?string $key = null): void
    {
        $key ??= $message;
        $count = count($this->noted);
        if (isset($this->noted[$key]) || $count > self::WARNING_LIMIT) {
            return;
        }
        if ($count === self::WARNING_LIMIT) {
            $message = 'More problems were found than are listed here';
        }
        $this->noted[$key] = true;
        $this->warnings[] = $message;
    }

    /** Bytes still free for working data. */
    public function room(): int
    {
        return $this->memory ?? Memory::left();
    }

    /** The most one decoded stream, or the text of one page, may take. */
    public function limit(): int
    {
        if ($this->memory !== null) {
            return max(1, intdiv($this->memory, 4));
        }
        return max(self::MIN_DECODED, min(self::MAX_DECODED, intdiv(Memory::left(), 4)));
    }

    /** @return list<int> */
    public function objectNumbers(): array
    {
        return array_keys($this->xref);
    }

    /** Follows references until it reaches an actual value. */
    public function resolve(mixed $v): mixed
    {
        for ($i = 0; $v instanceof Ref && $i < 32; $i++) {
            $v = $this->get($v->num);
        }
        return $v instanceof Ref ? null : $v;
    }

    /** Resolves a value and returns it only if it is a dictionary (a stream counts as its dictionary). */
    public function dict(mixed $v): ?array
    {
        $v = $this->resolve($v);
        if ($v instanceof Stream) {
            return $v->dict;
        }
        return is_array($v) ? $v : null;
    }

    /** How many capped() calls are on the stack; the outermost owns the budget. */
    private int $parsing = 0;

    /**
     * Parses within a budget on how many array elements and dictionary entries may be built, so a small
     * file cannot expand into millions of them. Budgets nest: only the outermost sets and clears the cap,
     * so the whole of one object is counted together. The depth is counted rather than read from the cap
     * itself, because an uncapped parse decrements the cap too and must not be mistaken for an open budget.
     */
    private function capped(\Closure $parse): mixed
    {
        if ($this->parsing === 0) {
            Lexer::$room = max(4096, intdiv($this->room(), 512));
        }
        $this->parsing++;
        try {
            return $parse();
        } finally {
            if (--$this->parsing === 0) {
                if (Lexer::$room <= 0) {
                    $this->warn('An object has more parts than fit in the memory that is left; the rest were left out');
                }
                Lexer::$room = PHP_INT_MAX;
            }
        }
    }

    public function get(int $num): mixed
    {
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        $entry = $this->xref[$num] ?? null;
        if ($entry === null) {
            return null;
        }

        if (($entry & 1) === 0) {
            $parsed = $this->capped(fn() => Lexer::indirect($this->data, $entry >> 1));
            if (($parsed === null || $parsed[0] !== $num) && !$this->rebuilt) {
                $this->rebuild();
                return $this->get($num);
            }
            $value = $parsed === null ? null : $parsed[2];
        } else {
            $value = $this->capped(fn() => $this->fromObjectStream($entry >> 1, $num));
        }

        if (count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
        }
        return $this->cache[$num] = $value;
    }

    /**
     * Decoded stream contents, or null when the stream holds image data.
     *
     * A stream that unpacks to more than $limit bytes (by default what limit() says) is cut off there.
     */
    public function streamData(Stream $s, bool $decrypt = true, ?int $limit = null): ?string
    {
        $limit ??= $this->limit();
        $cut = false;
        $data = $this->decode($s, $decrypt, $limit, $cut);
        if ($cut) {
            // Keyed by the stream, so reading the same one again under a smaller limit does not add a second note.
            $this->warn(sprintf('Stream %d is too large to unpack in the memory that is left (limit %s); the rest of it was left out', $s->num, self::size($limit)), "stream-too-large-$s->num");
        }
        return $data;
    }

    /** The first $bytes of a stream's decoded contents. The rest is never unpacked, and that is not worth a warning. */
    public function streamHead(Stream $s, int $bytes): ?string
    {
        $cut = false;
        return $this->decode($s, true, $bytes, $cut);
    }

    private function decode(Stream $s, bool $decrypt, int $limit, bool &$cut): ?string
    {
        $filters = $this->resolve($s->dict['Filter'] ?? null);
        $filters = $filters === null ? [] : (is_array($filters) ? array_map($this->resolve(...), $filters) : [$filters]);
        if (Filters::isImage($filters)) {
            return null;
        }
        $parms = $this->resolve($s->dict['DecodeParms'] ?? $s->dict['DP'] ?? null);
        if (is_array($parms) && array_is_list($parms)) {
            $parms = array_map($this->resolve(...), $parms);
        } else {
            $parms = [$parms];
        }

        $raw = $this->rawStream($s);
        if ($decrypt && $this->crypt !== null && ($s->dict['Type'] ?? null) !== '/XRef') {
            $raw = $this->crypt->decryptStream($raw, $s->num, $s->gen);
        }
        return Filters::decode($raw, $filters, $parms, $limit, $cut);
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576) . 'MB' : max(1, round($bytes / 1024)) . 'KB';
    }

    public function decryptString(PdfString $s, int $num, int $gen = 0): string
    {
        return $this->crypt === null ? $s->value : $this->crypt->decryptString($s, $num, $gen);
    }

    private function rawStream(Stream $s): string
    {
        $d = $this->data;
        $total = strlen($d);
        $len = $this->resolve($s->dict['Length'] ?? null);
        if (is_int($len) && $len >= 0 && $s->start + $len <= $total) {
            $q = $s->start + $len;
            $q += strspn($d, Lexer::WS, $q);
            if ($q + 9 <= $total && substr_compare($d, 'endstream', $q, 9) === 0) {
                return substr($d, $s->start, $len);
            }
        }
        // /Length is missing or wrong: take everything up to the endstream keyword.
        $end = false;
        if ($s->start < $this->noEndstream) {
            $end = strpos($d, 'endstream', $s->start);
            if ($end === false) {
                $this->noEndstream = $s->start;
            }
        }
        if ($end === false) {
            $end = strpos($d, 'endobj', $s->start);
        }
        $raw = substr($d, $s->start, ($end === false ? $total : $end) - $s->start);
        if (str_ends_with($raw, "\r\n")) {
            return substr($raw, 0, -2);
        }
        if (str_ends_with($raw, "\n") || str_ends_with($raw, "\r")) {
            return substr($raw, 0, -1);
        }
        return $raw;
    }

    private function fromObjectStream(int $container, int $num): mixed
    {
        if (!isset($this->objStreams[$container])) {
            $loaded = $this->loadObjectStream($container);
            // Keep few of them, and few bytes: each one can be as large as a stream may get.
            $kept = array_sum(array_map(static fn(array $o): int => strlen($o[0]), $this->objStreams)) + strlen($loaded[0]);
            while ($this->objStreams !== [] && (count($this->objStreams) >= self::OBJSTM_LIMIT || $kept > $this->limit())) {
                $oldest = array_key_first($this->objStreams);
                $kept -= strlen($this->objStreams[$oldest][0]);
                unset($this->objStreams[$oldest]);
            }
            $this->objStreams[$container] = $loaded;
        }
        if (array_key_last($this->objStreams) !== $container) {
            // Used again, so it goes to the back of the queue: the one dropped next is the one unused for longest.
            $entry = $this->objStreams[$container];
            unset($this->objStreams[$container]);
            $this->objStreams[$container] = $entry;
        }
        [$data, $offsets] = $this->objStreams[$container];
        if (!isset($offsets[$num])) {
            return null;
        }
        $p = $offsets[$num];
        return $this->capped(fn() => Lexer::value($data, $p));
    }

    /** @return array{0: string, 1: array<int, int>} */
    private function loadObjectStream(int $container): array
    {
        $entry = $this->xref[$container] ?? null;
        if ($entry === null || ($entry & 1) !== 0) {
            return ['', []];
        }
        $parsed = Lexer::indirect($this->data, $entry >> 1);
        $stream = $parsed[2] ?? null;
        if (!$stream instanceof Stream) {
            return ['', []];
        }
        $data = $this->streamData($stream) ?? '';
        $first = (int)$this->resolve($stream->dict['First'] ?? 0);
        // Each object listed costs about 80 bytes here and in the table, whatever the stream says it holds.
        $n = min((int)$this->resolve($stream->dict['N'] ?? 0), intdiv($this->room(), 320));
        return [$data, self::objectOffsets($data, $first, $n)];
    }

    /**
     * Object number => offset of its value, from the pairs of numbers at the start of an object stream.
     * The header is read in slices, so a header of millions of numbers does not become millions of strings at once.
     *
     * @return array<int, int>
     */
    private static function objectOffsets(string $data, int $first, int $count): array
    {
        $offsets = [];
        $length = max(0, min($first, strlen($data)));
        $number = null;
        $read = 0;
        for ($at = 0, $end = 0; $end < $length && $read < $count; $at = $end) {
            $end = min($length, $at + 65536);
            // Finish the number the slice ends in, so none is cut in two.
            $end += strspn($data, '0123456789', $end, $length - $end);
            preg_match_all('/\d+/', substr($data, $at, $end - $at), $m);
            foreach ($m[0] as $digits) {
                if ($number === null) {
                    $number = (int)$digits;
                    continue;
                }
                $offsets[$number] = $first + (int)$digits;
                $number = null;
                if (++$read >= $count) {
                    break;
                }
            }
        }
        return $offsets;
    }

    private function readXref(): void
    {
        $d = $this->data;
        $total = strlen($d);
        if (!preg_match_all('/startxref\s+(\d+)/', substr($d, -2048), $m)) {
            return;
        }
        $queue = [(int)end($m[1])];
        $seen = [];
        // Each section header may claim far more rows than follow it; copying the claimed amount every time
        // would cost more than the file is worth, so the copying is limited to a few times the file's size.
        $allowance = $total * 4 + 65536;
        while ($queue !== []) {
            $pos = array_shift($queue);
            if ($pos <= 0 || $pos >= $total || isset($seen[$pos])) {
                continue;
            }
            $seen[$pos] = true;
            $p = $pos;
            Lexer::skip($d, $p);

            if (substr_compare($d, 'xref', $p, 4) === 0) {
                $p += 4;
                while (preg_match('/\G\s*(\d+)\s+(\d+)\s*?[\r\n]/', $d, $h, 0, $p)) {
                    $p += strlen($h[0]);
                    $num = (int)$h[1];
                    $count = (int)$h[2];
                    // A real subsection of this many rows takes 20 bytes each. A header that claims far more
                    // than the file could hold is not telling the truth, and is given up on before any are read.
                    $allowance -= min($count, $total + 1) * 21;
                    if ($allowance < 0) {
                        throw new \RuntimeException('Cross-reference table is larger than the file');
                    }
                    // Read the rows a window at a time, so even an honest count never becomes one huge match array.
                    for ($i = 0; $i < $count; ) {
                        if (!preg_match_all('/\G\s*(\d{1,10})\s+(\d{1,5})\s+([nf])/', substr($d, $p, 65536), $rows, PREG_SET_ORDER)) {
                            break;
                        }
                        $consumed = 0;
                        foreach ($rows as $row) {
                            if ($i >= $count) {
                                break;
                            }
                            if ($row[3] === 'n' && !isset($this->xref[$num + $i])) {
                                $this->xref[$num + $i] = (int)$row[1] << 1;
                            }
                            $consumed += strlen($row[0]);
                            $i++;
                        }
                        if ($consumed === 0) {
                            break;
                        }
                        $p += $consumed;
                    }
                }
                $t = strpos($d, 'trailer', $p);
                if ($t === false || $t - $p > 4096) {
                    continue;
                }
                $t += 7;
                $dict = $this->capped(fn() => Lexer::value($d, $t));
            } else {
                $parsed = $this->capped(fn() => Lexer::indirect($d, $pos));
                $stream = $parsed[2] ?? null;
                if (!$stream instanceof Stream) {
                    continue;
                }
                $dict = $stream->dict;
                $this->readXrefStream($stream);
            }

            if (!is_array($dict)) {
                continue;
            }
            $this->trailer += $dict;
            if (is_int($dict['XRefStm'] ?? null)) {
                array_unshift($queue, $dict['XRefStm']);
            }
            if (is_int($dict['Prev'] ?? null)) {
                $queue[] = $dict['Prev'];
            }
        }
    }

    private function readXrefStream(Stream $stream): void
    {
        $dict = $stream->dict;
        $w = $dict['W'] ?? null;
        if (!is_array($w) || count($w) < 3) {
            return;
        }
        // The three field widths, in bytes. Anything that is not a small whole number means the table cannot be read.
        [$w0, $w1, $w2] = [$w[0] ?? null, $w[1] ?? null, $w[2] ?? null];
        foreach ([$w0, $w1, $w2] as $width) {
            if (!is_int($width) || $width < 0 || $width > 8) {
                return;
            }
        }
        $rowLen = $w0 + $w1 + $w2;
        if ($rowLen <= 0) {
            return;
        }
        // An entry takes at least a few bytes of the file, so a table longer than the file is not telling the truth.
        $data = $this->streamData($stream, false, strlen($this->data) + 65536) ?? '';
        $index = is_array($dict['Index'] ?? null) ? array_values($dict['Index']) : [0, $dict['Size'] ?? 0];
        $pos = 0;
        $len = min(strlen($data), max(1 << 16, intdiv(strlen($this->data), 2)) * $rowLen);
        for ($s = 0; $s + 1 < count($index); $s += 2) {
            $num = $index[$s];
            $count = $index[$s + 1];
            if (!is_int($num) || !is_int($count)) {
                break;
            }
            for ($i = 0; $i < $count && $pos + $rowLen <= $len; $i++, $pos += $rowLen) {
                $type = $w0 === 0 ? 1 : self::beInt($data, $pos, $w0);
                if (($type !== 1 && $type !== 2) || isset($this->xref[$num + $i])) {
                    continue;
                }
                $this->xref[$num + $i] = self::beInt($data, $pos + $w0, $w1) << 1 | ($type - 1);
            }
        }
    }

    /** Registers the objects found inside object streams, for a table rebuilt by scanning. */
    private function indexContainers(): void
    {
        foreach ($this->containers as $container) {
            try {
                [, $offsets] = $this->loadObjectStream($container);
            } catch (\Throwable) {
                continue;
            }
            foreach ($offsets as $num => $_) {
                if (!isset($this->xref[$num])) {
                    $this->xref[$num] = $container << 1 | 1;
                }
            }
        }
    }

    private static function beInt(string $d, int $p, int $n): int
    {
        $v = 0;
        for ($i = 0; $i < $n; $i++) {
            $v = ($v << 8) | ord($d[$p + $i]);
        }
        return $v;
    }

    /** Rebuilds the cross-reference table by scanning the whole file. Later definitions of an object replace earlier ones. */
    private function rebuild(): void
    {
        $this->rebuilt = true;
        $this->warn('Cross-reference table was missing or damaged; rebuilt by scanning the file');
        $d = $this->data;
        $xref = [];
        $containers = [];

        // One match at a time: a file that is nothing but "1 0 obj " would otherwise build a match array
        // of several hundred bytes for every eight bytes of file. Later definitions of a number replace earlier ones.
        $marker = '/(?<![0-9A-Za-z])(\d{1,10})[\0\t\n\f\r ]+\d{1,5}[\0\t\n\f\r ]+obj(?![A-Za-z])/';
        for ($at = 0; preg_match($marker, $d, $hit, PREG_OFFSET_CAPTURE, $at) === 1; $at = $hit[0][1] + strlen($hit[0][0])) {
            $xref[(int)$hit[1][0]] = $hit[0][1];
        }
        $this->xref = array_map(static fn(int $offset): int => $offset << 1, $xref);
        $this->cache = [];
        $this->objStreams = [];

        // Objects that live inside object streams, and trailer keys kept in cross-reference streams.
        $trailer = [];
        foreach ($xref as $num => $offset) {
            $head = substr($d, $offset, 512);
            $isObjStm = str_contains($head, 'ObjStm');
            $isXref = str_contains($head, 'XRef');
            if (!$isObjStm && !$isXref) {
                continue;
            }
            $parsed = $this->capped(fn() => Lexer::indirect($d, $offset));
            $stream = $parsed[2] ?? null;
            if (!$stream instanceof Stream) {
                continue;
            }
            $type = $stream->dict['Type'] ?? null;
            if ($type === '/ObjStm') {
                $containers[] = $num;
            } elseif ($type === '/XRef') {
                $trailer = $stream->dict + $trailer;
            }
        }

        $offset = 0;
        while (($t = strpos($d, 'trailer', $offset)) !== false) {
            $p = $t + 7;
            Lexer::skip($d, $p);
            // A trailer is a dictionary. The word on its own, or in front of anything else, means nothing, and
            // parsing from there would read to the end of the file. Carry on from past whatever this was.
            if (substr_compare($d, '<<', $p, 2) !== 0) {
                $offset = $t + 7;
                continue;
            }
            $dict = $this->capped(fn() => Lexer::value($d, $p));
            if (is_array($dict)) {
                $trailer = $dict + $trailer;
            }
            $offset = max($p, $t + 7);
        }

        if (!isset($trailer['Root'])) {
            foreach ($xref as $num => $offset) {
                if (preg_match('/\/Type\s*\/Catalog\b/', substr($d, $offset, 2048))) {
                    $parsed = $this->capped(fn() => Lexer::indirect($d, $offset));
                    if (is_array($parsed[2] ?? null) && ($parsed[2]['Type'] ?? null) === '/Catalog') {
                        $trailer['Root'] = new Ref($num, $parsed[1]);
                    }
                }
            }
        }
        $this->trailer = $trailer + $this->trailer;

        $this->containers = $containers;
        $this->indexContainers();

        if (!isset($this->trailer['Root'])) {
            foreach (array_keys($this->xref) as $num) {
                $v = $this->get($num);
                if (is_array($v) && ($v['Type'] ?? null) === '/Catalog') {
                    $this->trailer['Root'] = new Ref($num, 0);
                    break;
                }
            }
        }
    }
}
