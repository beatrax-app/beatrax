<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// What the log can prove about one parent id a peer sent. Only `Misfiled`
// carries a target; the other two are the refusals, kept apart because the
// remedy differs -- one waits on a create being taken again, the other on the
// peer speaking for the row at all.
/**
 * @link ../../../../.docs/features/sync/architecture.md#an-id-that-crossed-before-its-alias-existed
 */
enum UntranslatedParentVerdict: string
{
    case Misfiled = 'misfiled';

    case Unplaceable = 'unplaceable';

    case Unspoken = 'unspoken';
}
