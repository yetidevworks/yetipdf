<?php

declare(strict_types=1);

namespace YetiPdf\Font;

/** Anything that can say which text a character code stands for. */
interface CodeMap
{
    /** The text for a code, or null when this map does not know it. */
    public function get(int $code): ?string;
}
