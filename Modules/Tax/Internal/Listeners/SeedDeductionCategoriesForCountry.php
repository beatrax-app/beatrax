<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Listeners;

use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserCountryChanged;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;

// The country is chosen in three places now — signup, settings and the setup
// wizard — and only this module knows a corpus has to follow it there.
final class SeedDeductionCategoriesForCountry
{
    public function __construct(
        private readonly TaxCategoryWriter $writer,
    ) {}

    public function handle(UserCountryChanged $event): void
    {
        $user = User::query()->find($event->userId);
        if ($user === null) {
            return;
        }

        $this->writer->seedFromCorpus($user, $event->countryCode);
    }
}
