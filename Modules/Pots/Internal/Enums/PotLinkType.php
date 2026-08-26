<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Enums;

// What the pot form's "link to" radio pair offers. Category-linked pots were a
// third case once and are no longer creatable, which is why a pot carrying a
// lingering category_id still edits back as None.
enum PotLinkType: string
{
    case Goal = 'goal';

    case None = 'none';
}
