<?php

declare(strict_types=1);

namespace YetiPdf\Read\Exception;

/** The input is not a PDF, or is too damaged to find a single page in. */
class InvalidPdfException extends PdfException
{
}

// The name this class had before 1.3 (yetidevworks/yetipdf), kept until 2.0: see legacy.php.
if (!\class_exists('YetiPdf\\Exception\\InvalidPdfException', false)) {
    \class_alias(InvalidPdfException::class, 'YetiPdf\\Exception\\InvalidPdfException', false);
}
