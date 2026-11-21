<?php

declare(strict_types=1);

namespace YetiPdf\Read\Exception;

class PdfException extends \RuntimeException
{
}

// The name this class had before 1.3 (yetidevworks/yetipdf), kept until 2.0: see legacy.php.
if (!\class_exists('YetiPdf\\Exception\\PdfException', false)) {
    \class_alias(PdfException::class, 'YetiPdf\\Exception\\PdfException', false);
}
