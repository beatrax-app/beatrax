<?php

declare(strict_types=1);

namespace Modules\Search\Public\Enums;

// Which kind of thing an entity hit names. The value reaches the client as the
// discriminator the palette template groups its sections by, so it is the
// contract between the entity search and the modal that renders it.
enum SearchEntityKind: string
{
    case Counterparty = 'counterparty';

    case Category = 'category';

    case Goal = 'goal';

    case Pot = 'pot';

    case Recurring = 'recurring';
}
