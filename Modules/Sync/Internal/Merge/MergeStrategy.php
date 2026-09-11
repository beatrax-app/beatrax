<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// How a field's competing writes are reconciled. Lww is the answer for all but
// three fields in the registry, which is why it is the default there rather
// than a key repeated a hundred and seventeen times.
enum MergeStrategy: string
{
    case Lww = 'lww';

    case GCounter = 'g_counter';

    case OrSet = 'or_set';

    // A JSON object whose keys are independent facts. Lww replaces the map
    // whole, so a device announcing the keys it holds erases the ones the other
    // device stamped.
    case JsonKeyUnion = 'json_key_union';
}
