<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Enums\Country;
use Modules\Core\Public\Events\UserCountryChanged;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\LocaleCollator;

// The reader's own country, beside their language and theme rather than inside
// Tax: it decides which country's tax rules, government bodies and bank fees
// the app recognises, and more than one module now scopes to it.
final class UserCountry
{
    // Read once per reader rather than once per row: classification asks on
    // every transaction of an import, and two services each kept their own
    // copy of the answer. The write below is the only thing that can change
    // it, so it is also the only thing that has to drop this.
    /** @var array<int, string> */
    private array $currentByUser = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly WriteUserPreference $writeUserPreference,
        private readonly Dispatcher $events,
    ) {}

    // An empty string, not null: "no country chosen" is a real state that widens
    // classification to every region, and every caller compares against ''.
    public function current(int $userId): string
    {
        if (array_key_exists($userId, $this->currentByUser)) {
            return $this->currentByUser[$userId];
        }

        /** @var mixed $code */
        $code = $this->db->connection()
            ->table('users')
            ->where('id', $userId)
            ->value('country_code');

        return $this->currentByUser[$userId] = is_string($code) ? $code : '';
    }

    // Seeded before it is written, and both inside one transaction: a corpus
    // that throws half way must not leave the country set with nothing behind
    // it, which every empty state reads as an install that needs no help.
    public function store(int $userId, string $countryCode): void
    {
        if (Country::tryFrom($countryCode) === null) {
            return;
        }

        $this->db->connection()->transaction(function () use ($userId, $countryCode): void {
            $this->events->dispatch(new UserCountryChanged($userId, $countryCode));

            ($this->writeUserPreference)($userId, ['country_code' => $countryCode]);
        });

        unset($this->currentByUser[$userId]);
    }

    /**
     * @return array<string, string> code => display label
     */
    public function options(): array
    {
        $countries = [];
        foreach (Country::cases() as $country) {
            $countries[$country->value] = Lang::get('core::settings.country.countries.'.$country->value);
        }

        $this->sortByLabel($countries);

        return $countries;
    }

    // The reader scans the NAMES, so the names decide the order; it was the
    // enum's ISO-code order, with no search box to recover with.
    /**
     * @param  array<string, string>  $countries
     */
    private function sortByLabel(array &$countries): void
    {
        uasort($countries, static fn (string $a, string $b): int => LocaleCollator::compare($a, $b));
    }
}
