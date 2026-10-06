<?php

declare(strict_types=1);

namespace YetiPdf\Exception;

/** The input is not a PDF, or is too damaged to find a single page in. */
class InvalidPdfException extends PdfException
{
}
