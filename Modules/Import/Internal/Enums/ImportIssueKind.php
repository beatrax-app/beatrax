<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Enums;

enum ImportIssueKind: string
{
    case FileError = 'file_error';

    case RowError = 'row_error';

    case Duplicate = 'duplicate';
}
