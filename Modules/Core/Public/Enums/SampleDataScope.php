<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// How much of an installation the sample dataset is allowed to touch. The
// difference is not cosmetic: two of the seeders write the install's own state
// rather than its ledger, and running those over a real account replaces the
// reader's recovery codes and reopens their onboarding.
enum SampleDataScope: string
{
    // The developer path. Every seeder, over accounts the seeding invented.
    case WholeInstall = 'whole_install';

    // The reader path. The ledger and everything derived from it, over an
    // account that already exists and belongs to somebody.
    case LedgerOnly = 'ledger_only';

    public function reachesInstallState(): bool
    {
        return $this === self::WholeInstall;
    }
}
