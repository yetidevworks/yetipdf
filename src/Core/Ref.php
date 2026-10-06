<?php

declare(strict_types=1);

namespace YetiPdf\Core;

/** An indirect reference: "12 0 R". */
final class Ref
{
    public function __construct(public readonly int $num, public readonly int $gen = 0)
    {
    }
}
