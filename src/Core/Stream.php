<?php

declare(strict_types=1);

namespace YetiPdf\Core;

/** A stream object. Holds its dictionary and where its bytes start; the data is read and decoded on demand. */
final class Stream
{
    /** @param array<string, mixed> $dict */
    public function __construct(
        public readonly array $dict,
        public readonly int $start,
        public readonly int $num,
        public readonly int $gen,
    ) {
    }
}
