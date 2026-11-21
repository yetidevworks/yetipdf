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
