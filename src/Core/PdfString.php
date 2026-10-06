<?php

declare(strict_types=1);

namespace YetiPdf\Core;

/** A literal or hex string. Wrapped so it can be told apart from a name, which is a plain PHP string starting with "/". */
final class PdfString
{
    public function __construct(public string $value)
    {
    }
}
