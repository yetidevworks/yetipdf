<?php

declare(strict_types=1);

namespace YetiPdf\Font;

/**
 * Text for the CIDs of one of Adobe's CJK character collections (Japan1, GB1, CNS1, Korea1, KR).
 *
 * A composite font that uses Identity-H with no ToUnicode CMap gives each glyph as a CID of its collection,
 * and the only way to know the character is Adobe's published table. The tables live in data/cid, one file
 * per ordering, and are described in tools/build-cid-tables.php. A table is read the first time a font needs
 * it and kept for the rest of the process. A missing or damaged file means every CID is unknown; nothing here throws.
 */
final class CidCollection implements CodeMap
{
    public const ORDERINGS = ['Japan1', 'GB1', 'CNS1', 'Korea1', 'KR'];

    /** Values from this one up point into the string area instead of being a code point. */
    private const STRING_BASE = 0x110000;

    /** @var array<string, array{0: string, 1: int}|null> ordering => [payload, CID count], or null when it cannot be read */
    private static array $tables = [];

    /** @var array{0: string, 1: int}|null */
    private ?array $table;

    public function __construct(string $ordering)
    {
        $this->table = self::table($ordering);
    }

    /** True when this ordering has a table that loads. */
    public static function supports(string $ordering): bool
    {
        return self::table($ordering) !== null;
    }

    public function get(int $code): ?string
    {
        if ($this->table === null || $code < 1) {
            return null;
        }
        [$data, $count] = $this->table;
        if ($code >= $count) {
            return null;
        }
        $value = (ord($data[4 + $code]) << 16) | (ord($data[4 + $count + $code]) << 8) | ord($data[4 + 2 * $count + $code]);
        if ($value === 0) {
            return null;
        }
        if ($value < self::STRING_BASE) {
            return Utf::chr($value);
        }
        $at = 4 + 3 * $count + $value - self::STRING_BASE;
        if (!isset($data[$at])) {
            return null;
        }
        $length = ord($data[$at]);
        return ($length > 0 && $at + $length < strlen($data)) ? substr($data, $at + 1, $length) : null;
    }

    /** @return array{0: string, 1: int}|null */
    private static function table(string $ordering): ?array
    {
        if (array_key_exists($ordering, self::$tables)) {
            return self::$tables[$ordering];
        }
        $table = null;
        if (in_array($ordering, self::ORDERINGS, true)) {
            $file = @file_get_contents(dirname(__DIR__, 2) . '/data/cid/' . $ordering . '.bin');
            if (is_string($file) && strlen($file) > 8 && str_starts_with($file, 'YCD1')) {
                $size = (int)unpack('N', $file, 4)[1];
                $data = ($size >= 4 && $size <= 0x1000000) ? @gzinflate(substr($file, 8), $size + 1) : false;
                if ($data !== false && strlen($data) === $size) {
                    $count = (int)unpack('N', $data)[1];
                    if ($size >= 4 + 3 * $count) {
                        $table = [$data, $count];
                    }
                }
            }
        }
        return self::$tables[$ordering] = $table;
    }
}
