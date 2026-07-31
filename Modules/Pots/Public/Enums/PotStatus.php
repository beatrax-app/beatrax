<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Enums;

// The lifecycle of a pots row: `active` (default) until the user
// `archived` it. The column stays string; this enum is the one canonical
// spelling callers map through.
/**
 * @link ../../../../.docs/features/pots/architecture.md
 */
enum PotStatus: string
{
    case Active = 'active';

    case Archived = 'archived';
}
