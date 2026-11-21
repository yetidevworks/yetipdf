<?php

declare(strict_types=1);

namespace YetiPdf\Read\Core;

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

// The name this class had before 1.3 (yetidevworks/yetipdf), kept until 2.0: see legacy.php.
if (!\class_exists('YetiPdf\\Core\\Stream', false)) {
    \class_alias(Stream::class, 'YetiPdf\\Core\\Stream', false);
}
