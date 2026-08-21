<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

// What one source's slice of the consolidated preview is offering the reader.
// `Empty` and `Error` both commit nothing, and telling them apart is the whole
// point: one says the statement held nothing new, the other that it would not
// read.
enum PreviewSectionStatus: string
{
    case Ready = 'ready';

    case Empty = 'empty';

    case Error = 'error';
}
