<?php

declare(strict_types=1);

namespace YetiPdf\Filter;

/**
 * Stream decoders. Only the filters that can carry text or document structure are implemented;
 * image codecs return null so callers can skip the stream without decompressing it.
 */
final class Filters
{
    private const IMAGE = [
        '/DCTDecode' => true, '/DCT' => true, '/JPXDecode' => true, '/CCITTFaxDecode' => true,
        '/CCF' => true, '/JBIG2Decode' => true,
    ];

    /**
     * @param list<mixed> $filters filter names, in the order they must be applied
     * @param list<mixed> $parms   decode parameter dictionaries, parallel to $filters
     */
    public static function decode(string $data, array $filters, array $parms): ?string
    {
        foreach ($filters as $i => $filter) {
            if (!is_string($filter)) {
                continue;
            }
            if (isset(self::IMAGE[$filter])) {
                return null;
            }
            $p = is_array($parms[$i] ?? null) ? $parms[$i] : [];
            switch ($filter) {
                case '/FlateDecode':
                case '/Fl':
                    $data = self::predict(self::flate($data), $p);
                    break;
                case '/LZWDecode':
                case '/LZW':
                    $data = self::predict(self::lzw($data, (int)($p['EarlyChange'] ?? 1)), $p);
                    break;
                case '/ASCII85Decode':
                case '/A85':
                    $data = self::ascii85($data);
                    break;
                case '/ASCIIHexDecode':
                case '/AHx':
                    $data = self::asciiHex($data);
                    break;
                case '/RunLengthDecode':
                case '/RL':
                    $data = self::runLength($data);
                    break;
                default:
                    // /Crypt and anything unknown: pass the bytes through.
                    break;
            }
        }
        return $data;
    }

    public static function isImage(mixed $filter): bool
    {
        foreach (is_array($filter) ? $filter : [$filter] as $f) {
            if (is_string($f) && isset(self::IMAGE[$f])) {
                return true;
            }
        }
        return false;
    }

    public static function flate(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $out = @gzuncompress($data);
        if ($out !== false) {
            return $out;
        }
        $out = @gzinflate($data);
        if ($out !== false) {
            return $out;
        }
        // Damaged or truncated: feed the decoder in pieces and keep whatever comes out before it gives up.
        $best = '';
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $encoding) {
            $ctx = @inflate_init($encoding);
            if ($ctx === false) {
                continue;
            }
            $out = '';
            $len = strlen($data);
            for ($i = 0; $i < $len; $i += 4096) {
                $chunk = @inflate_add($ctx, substr($data, $i, 4096), ZLIB_SYNC_FLUSH);
                if ($chunk === false) {
                    break;
                }
                $out .= $chunk;
            }
            if (strlen($out) > strlen($best)) {
                $best = $out;
            }
        }
        return $best;
    }

    /** @param array<string, mixed> $p */
    public static function predict(string $data, array $p): string
    {
        $predictor = (int)($p['Predictor'] ?? 1);
        if ($predictor <= 1 || $data === '') {
            return $data;
        }
        $colors = max(1, (int)($p['Colors'] ?? 1));
        $bpc = max(1, (int)($p['BitsPerComponent'] ?? 8));
        $columns = max(1, (int)($p['Columns'] ?? 1));
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));
        $rowLen = intdiv($colors * $bpc * $columns + 7, 8);

        if ($predictor === 2) {
            if ($bpc !== 8) {
                return $data;
            }
            $out = '';
            foreach (str_split($data, $rowLen) as $row) {
                $b = array_values(unpack('C*', $row));
                for ($i = $bpp, $n = count($b); $i < $n; $i++) {
                    $b[$i] = ($b[$i] + $b[$i - $bpp]) & 0xFF;
                }
                $out .= pack('C*', ...$b);
            }
            return $out;
        }

        $out = '';
        $prev = array_fill(0, $rowLen, 0);
        $len = strlen($data);
        for ($pos = 0; $pos < $len; $pos += $rowLen + 1) {
            $type = ord($data[$pos]);
            $rowStr = substr($data, $pos + 1, $rowLen);
            if ($type === 0) {
                $out .= $rowStr;
                $prev = array_pad(array_values(unpack('C*', $rowStr) ?: []), $rowLen, 0);
                continue;
            }
            $row = array_pad(array_values(unpack('C*', $rowStr) ?: []), $rowLen, 0);
            switch ($type) {
                case 1:
                    for ($i = $bpp; $i < $rowLen; $i++) {
                        $row[$i] = ($row[$i] + $row[$i - $bpp]) & 0xFF;
                    }
                    break;
                case 2:
                    for ($i = 0; $i < $rowLen; $i++) {
                        $row[$i] = ($row[$i] + $prev[$i]) & 0xFF;
                    }
                    break;
                case 3:
                    for ($i = 0; $i < $rowLen; $i++) {
                        $left = $i >= $bpp ? $row[$i - $bpp] : 0;
                        $row[$i] = ($row[$i] + (($left + $prev[$i]) >> 1)) & 0xFF;
                    }
                    break;
                case 4:
                    for ($i = 0; $i < $rowLen; $i++) {
                        $a = $i >= $bpp ? $row[$i - $bpp] : 0;
                        $b = $prev[$i];
                        $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                        $pa = abs($b - $c);
                        $pb = abs($a - $c);
                        $pc = abs($a + $b - 2 * $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $row[$i] = ($row[$i] + $pr) & 0xFF;
                    }
                    break;
            }
            $out .= pack('C*', ...$row);
            $prev = $row;
        }
        return $out;
    }

    public static function lzw(string $data, int $earlyChange = 1): string
    {
        $out = '';
        $table = [];
        for ($i = 0; $i < 256; $i++) {
            $table[$i] = chr($i);
        }
        $next = 258;
        $bits = 9;
        $buf = 0;
        $bufBits = 0;
        $prev = null;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $buf = ($buf << 8) | ord($data[$i]);
            $bufBits += 8;
            while ($bufBits >= $bits) {
                $code = ($buf >> ($bufBits - $bits)) & ((1 << $bits) - 1);
                $bufBits -= $bits;
                $buf &= (1 << $bufBits) - 1;
                if ($code === 256) {
                    $table = array_slice($table, 0, 256);
                    $next = 258;
                    $bits = 9;
                    $prev = null;
                    continue;
                }
                if ($code === 257) {
                    return $out;
                }
                if ($prev === null) {
                    $entry = $table[$code] ?? '';
                } elseif (isset($table[$code])) {
                    $entry = $table[$code];
                    $table[$next++] = $prev . $entry[0];
                } else {
                    $entry = $prev . $prev[0];
                    $table[$next++] = $entry;
                }
                $out .= $entry;
                $prev = $entry === '' ? null : $entry;
                if ($next + $earlyChange >= (1 << $bits) && $bits < 12) {
                    $bits++;
                }
            }
        }
        return $out;
    }

    public static function ascii85(string $data): string
    {
        $end = strpos($data, '~>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $data = preg_replace('/[^\x21-\x75z]+/', '', $data) ?? '';
        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }
        $out = '';
        $len = strlen($data);
        $i = 0;
        while ($i < $len) {
            if ($data[$i] === 'z') {
                $out .= "\0\0\0\0";
                $i++;
                continue;
            }
            $group = substr($data, $i, 5);
            $i += 5;
            $n = strlen($group);
            if ($n < 2) {
                break;
            }
            $group = str_pad($group, 5, 'u');
            $v = 0;
            for ($k = 0; $k < 5; $k++) {
                $v = $v * 85 + (ord($group[$k]) - 33);
            }
            $out .= substr(pack('N', $v & 0xFFFFFFFF), 0, $n - 1);
        }
        return $out;
    }

    public static function asciiHex(string $data): string
    {
        $end = strpos($data, '>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $data = preg_replace('/[^0-9A-Fa-f]+/', '', $data) ?? '';
        if (strlen($data) & 1) {
            $data .= '0';
        }
        return $data === '' ? '' : (string)hex2bin($data);
    }

    public static function runLength(string $data): string
    {
        $out = '';
        $len = strlen($data);
        $i = 0;
        while ($i < $len) {
            $n = ord($data[$i++]);
            if ($n === 128) {
                break;
            }
            if ($n < 128) {
                $out .= substr($data, $i, $n + 1);
                $i += $n + 1;
            } elseif ($i < $len) {
                $out .= str_repeat($data[$i++], 257 - $n);
            }
        }
        return $out;
    }
}
