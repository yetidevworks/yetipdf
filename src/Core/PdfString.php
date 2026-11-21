<?php

declare(strict_types=1);

namespace YetiPdf\Read\Core;

/** A literal or hex string. Wrapped so it can be told apart from a name, which is a plain PHP string starting with "/". */
final class PdfString
{
    public function __construct(public string $value)
    {
    }
}

// The name this class had before 1.3 (yetidevworks/yetipdf), kept until 2.0: see legacy.php.
if (!\class_exists('YetiPdf\\Core\\PdfString', false)) {
    \class_alias(PdfString::class, 'YetiPdf\\Core\\PdfString', false);
}
