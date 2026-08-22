<?php

declare(strict_types=1);

namespace Modules\Core\Public\Events;

// Raised after the preference is stored, so a module that keeps country-scoped
// reference data can catch up without the four pickers each having to know
// which modules those are.
final class UserCountryChanged
{
    // False when this install is JOINING an existing account and receives its
    // country-scoped reference data from a peer — the same decision, and reason,
    // as UserInstalled::$seedsStarterData. Ids start at 1 on both devices, so a
    // synced table the joiner seeded swallows the peer's row of that id in silence.
    public function __construct(
        public int $userId,
        public string $countryCode,
        public bool $seedsCountryData = true,
    ) {}
}
