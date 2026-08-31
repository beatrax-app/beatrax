<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Listeners;

use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserCountryChanged;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Services\UserCountry;
use Modules\Tax\Internal\Actions\TaxCategoryStore;

// The country is chosen in four places now — signup, the phone's import
// screen, settings and the setup wizard — and only this module knows a corpus
// has to follow it there.
final readonly class SeedDeductionCategoriesForCountry
{
    public function __construct(
        private TaxCategoryStore $writer,
        private UserCountry $countries,
    ) {}

    public function handle(UserCountryChanged $event): void
    {
        // A device joining another account receives this corpus from its peer.
        // tax_deduction_categories is synced and the joiner is epoch-less until
        // pairing confirms, so a row seeded here is never pushed — while the
        // peer's row of the same id is dropped on arrival by insertOrIgnore.
        if (! $event->seedsCountryData) {
            return;
        }

        $this->seedFor($event->userId, $event->countryCode);
    }

    // The other half: the join that never completes. Leaving the import flow
    // re-dispatches UserInstalled to make good what the join withheld, and this
    // corpus hangs off the country rather than the install, so nothing else
    // would send it. Reading the stored country leaves an unchosen install be.
    public function handleInstall(UserInstalled $event): void
    {
        if (! $event->seedsStarterData) {
            return;
        }

        $this->seedFor($event->userId, $this->countries->current($event->userId));
    }

    private function seedFor(int $userId, string $countryCode): void
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            return;
        }

        $this->writer->seedFromCorpus($user, $countryCode);
    }
}
