<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// How a field's competing writes are reconciled. Lww is the answer for all but
// two fields in the registry, which is why it is the default there rather than
// a key repeated a hundred and seventeen times.
enum MergeStrategy: string
{
    case Lww = 'lww';

    case GCounter = 'g_counter';

    case OrSet = 'or_set';
}
