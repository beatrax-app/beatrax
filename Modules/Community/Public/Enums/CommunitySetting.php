<?php

declare(strict_types=1);

namespace Modules\Community\Public\Enums;

// The keys stored in the `users.community_settings` JSON column. The panel that
// writes them and the readers that gate on them are in different modules, so a
// literal on either side is a toggle that silently stops working.
enum CommunitySetting: string
{
    case UseSharedList = 'useSharedList';

    case OfferToContribute = 'offerToContribute';

    case UpdateOnAppUpdates = 'updateOnAppUpdates';

    // What a reader who has never opened the panel gets. Consulting the bundled
    // list and being offered the contribute CTA are on; nothing implements
    // automatic corpus refresh, so the third is off and its switch is disabled.
    public function default(): bool
    {
        return match ($this) {
            self::UseSharedList, self::OfferToContribute => true,
            self::UpdateOnAppUpdates => false,
        };
    }
}
