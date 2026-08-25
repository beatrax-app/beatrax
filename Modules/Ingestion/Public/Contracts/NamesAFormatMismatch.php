<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Contracts;

// Thrown when the file is not the format the reader declared: a header that
// does not match, a PayPal export in an unregistered language or the wrong
// report shape, a format id nothing can read. Import reads this to decide
// whether "check the bank and the format" is advice or a wrong guess.
interface NamesAFormatMismatch {}
