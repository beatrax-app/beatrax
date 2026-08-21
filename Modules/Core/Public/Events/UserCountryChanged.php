<?php

declare(strict_types=1);

namespace Modules\Core\Public\Events;

// Raised after the preference is stored, so a module that keeps country-scoped
// reference data can catch up without the three pickers each having to know
// which modules those are.
final class UserCountryChanged
{
    public function __construct(
        public int $userId,
        public string $countryCode,
    ) {}
}
