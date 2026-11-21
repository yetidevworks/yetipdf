<?php

declare(strict_types=1);

namespace YetiPdf\Read\Core;

/** An indirect reference: "12 0 R". */
final class Ref
{
    public function __construct(public readonly int $num, public readonly int $gen = 0)
    {
    }
}

// The name this class had before 1.3 (yetidevworks/yetipdf), kept until 2.0: see legacy.php.
if (!\class_exists('YetiPdf\\Core\\Ref', false)) {
    \class_alias(Ref::class, 'YetiPdf\\Core\\Ref', false);
}
