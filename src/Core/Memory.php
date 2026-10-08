<?php

declare(strict_types=1);

namespace YetiPdf\Core;

/** How much memory is left to work with. The limits that guard against hostile files scale with it. */
final class Memory
{
    /** What counts as left when PHP has no memory limit, so a hostile file still meets a ceiling. */
    private const CEILING = 2 << 30;

    /** Bytes that can still be allocated before PHP's memory_limit is hit, never more than two gigabytes. */
    public static function left(): int
    {
        $limit = self::limit();
        return $limit === null ? self::CEILING : min(self::CEILING, max(0, $limit - memory_get_usage(true)));
    }

    /**
     * Whether so little is left that nothing more should be kept for later: fonts stop being read and stop
     * remembering what they decoded. "Little" is an eighth of the limit, and between 4MB and 16MB, so a
     * process that was only ever given 16MB is not treated as out of memory from the start.
     */
    public static function short(): bool
    {
        $limit = self::limit();
        return $limit !== null && $limit - memory_get_usage(true) < max(4 << 20, min(16 << 20, $limit >> 3));
    }

    /** PHP's memory_limit in bytes, or null when there is none. */
    private static function limit(): ?int
    {
        $raw = trim((string)ini_get('memory_limit'));
        $bytes = (int)$raw;
        if ($bytes <= 0) {
            return null;
        }
        return match (strtolower(substr($raw, -1))) {
            'g' => $bytes << 30,
            'm' => $bytes << 20,
            'k' => $bytes << 10,
            default => $bytes,
        };
    }
}
