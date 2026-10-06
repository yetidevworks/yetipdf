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
    private const CACHE_LIMIT = 4096;

    /** @var array<int, array{0: int, 1: int, 2: int}> object number => [type, offset | container, generation | index] */
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
    /** @var list<string> */
    public array $warnings = [];

    public function __construct(private readonly string $data, string $password = '')
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

    public function get(int $num): mixed
    {
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        $entry = $this->xref[$num] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($entry[0] === 1) {
            $parsed = Lexer::indirect($this->data, $entry[1]);
            if (($parsed === null || $parsed[0] !== $num) && !$this->rebuilt) {
                $this->rebuild();
                return $this->get($num);
            }
            $value = $parsed === null ? null : $parsed[2];
        } else {
            $value = $this->fromObjectStream($entry[1], $num);
        }

        if (count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
        }
        return $this->cache[$num] = $value;
    }

    /** Decoded stream contents, or null when the stream holds image data. */
    public function streamData(Stream $s, bool $decrypt = true): ?string
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
        return Filters::decode($raw, $filters, $parms);
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
        $end = strpos($d, 'endstream', $s->start);
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
            $this->objStreams[$container] = $this->loadObjectStream($container);
        }
        [$data, $offsets] = $this->objStreams[$container];
        if (!isset($offsets[$num])) {
            return null;
        }
        $p = $offsets[$num];
        return Lexer::value($data, $p);
    }

    /** @return array{0: string, 1: array<int, int>} */
    private function loadObjectStream(int $container): array
    {
        $entry = $this->xref[$container] ?? null;
        if ($entry === null || $entry[0] !== 1) {
            return ['', []];
        }
        $parsed = Lexer::indirect($this->data, $entry[1]);
        $stream = $parsed[2] ?? null;
        if (!$stream instanceof Stream) {
            return ['', []];
        }
        $data = $this->streamData($stream) ?? '';
        $first = (int)$this->resolve($stream->dict['First'] ?? 0);
        $n = (int)$this->resolve($stream->dict['N'] ?? 0);
        $offsets = [];
        if (preg_match_all('/(\d+)\s+(\d+)/', substr($data, 0, $first), $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $pair) {
                if ($i >= $n) {
                    break;
                }
                $offsets[(int)$pair[1]] = $first + (int)$pair[2];
            }
        }
        return [$data, $offsets];
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
                    $chunk = substr($d, $p, $count * 21 + 64);
                    preg_match_all('/\G\s*(\d{1,10})\s+(\d{1,5})\s+([nf])/', $chunk, $rows, PREG_SET_ORDER);
                    $consumed = 0;
                    foreach ($rows as $i => $row) {
                        if ($i >= $count) {
                            break;
                        }
                        if ($row[3] === 'n' && !isset($this->xref[$num + $i])) {
                            $this->xref[$num + $i] = [1, (int)$row[1], (int)$row[2]];
                        }
                        $consumed += strlen($row[0]);
                    }
                    $p += $consumed;
                }
                $t = strpos($d, 'trailer', $p);
                if ($t === false || $t - $p > 4096) {
                    continue;
                }
                $t += 7;
                $dict = Lexer::value($d, $t);
            } else {
                $parsed = Lexer::indirect($d, $pos);
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
        [$w0, $w1, $w2] = [(int)$w[0], (int)$w[1], (int)$w[2]];
        $rowLen = $w0 + $w1 + $w2;
        if ($rowLen <= 0) {
            return;
        }
        $data = $this->streamData($stream, false) ?? '';
        $index = is_array($dict['Index'] ?? null) ? $dict['Index'] : [0, (int)($dict['Size'] ?? 0)];
        $pos = 0;
        $len = strlen($data);
        for ($s = 0; $s + 1 < count($index); $s += 2) {
            $num = (int)$index[$s];
            $count = (int)$index[$s + 1];
            for ($i = 0; $i < $count && $pos + $rowLen <= $len; $i++, $pos += $rowLen) {
                $type = $w0 === 0 ? 1 : self::beInt($data, $pos, $w0);
                if (($type !== 1 && $type !== 2) || isset($this->xref[$num + $i])) {
                    continue;
                }
                $this->xref[$num + $i] = [$type, self::beInt($data, $pos + $w0, $w1), self::beInt($data, $pos + $w0 + $w1, $w2)];
            }
        }
    }

    /** Registers the objects found inside object streams, for a table rebuilt by scanning. */
    private function indexContainers(): void
    {
        foreach ($this->containers as $container) {
            try {
                [, $offsets] = $this->objStreams[$container] = $this->loadObjectStream($container);
            } catch (\Throwable) {
                continue;
            }
            foreach ($offsets as $num => $_) {
                if (!isset($this->xref[$num])) {
                    $this->xref[$num] = [2, $container, 0];
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
        $this->warnings[] = 'Cross-reference table was missing or damaged; rebuilt by scanning the file';
        $d = $this->data;
        $xref = [];
        $containers = [];

        if (preg_match_all('/(?<![0-9A-Za-z])(\d{1,10})[\0\t\n\f\r ]+(\d{1,5})[\0\t\n\f\r ]+obj(?![A-Za-z])/', $d, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $xref[(int)$hit[1][0]] = [1, $hit[0][1], (int)$hit[2][0]];
            }
        }
        $this->xref = $xref;
        $this->cache = [];
        $this->objStreams = [];

        // Objects that live inside object streams, and trailer keys kept in cross-reference streams.
        $trailer = [];
        foreach ($xref as $num => $entry) {
            $head = substr($d, $entry[1], 512);
            $isObjStm = str_contains($head, 'ObjStm');
            $isXref = str_contains($head, 'XRef');
            if (!$isObjStm && !$isXref) {
                continue;
            }
            $parsed = Lexer::indirect($d, $entry[1]);
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
            $dict = Lexer::value($d, $p);
            if (is_array($dict)) {
                $trailer = $dict + $trailer;
            }
            $offset = $t + 7;
        }

        if (!isset($trailer['Root'])) {
            foreach ($xref as $num => $entry) {
                if (preg_match('/\/Type\s*\/Catalog\b/', substr($d, $entry[1], 2048))) {
                    $parsed = Lexer::indirect($d, $entry[1]);
                    if (is_array($parsed[2] ?? null) && ($parsed[2]['Type'] ?? null) === '/Catalog') {
                        $trailer['Root'] = new Ref($num, $entry[2]);
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
