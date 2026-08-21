<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Enums;

enum PreviewRowStatus: string
{
    case NewRow = 'new';

    case Duplicate = 'duplicate';

    case Enriched = 'enriched';

    case Error = 'error';
}
