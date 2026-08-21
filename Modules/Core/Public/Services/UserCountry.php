<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Collator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use IntlException;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Enums\Country;
use Modules\Core\Public\Events\UserCountryChanged;
use Modules\Core\Public\Support\Lang;

// The reader's own country, beside their language and theme rather than inside
// Tax: it decides which country's tax rules, government bodies and bank fees
// the app recognises, and more than one module now scopes to it.
final class UserCountry
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly WriteUserPreference $writeUserPreference,
        private readonly Dispatcher $events,
        private readonly Translator $translator,
    ) {}

    // An empty string, not null: "no country chosen" is a real state that widens
    // classification to every region, and every caller compares against ''.
    public function current(int $userId): string
    {
        /** @var mixed $code */
        $code = $this->db->connection()
            ->table('users')
            ->where('id', $userId)
            ->value('country_code');

        return is_string($code) ? $code : '';
    }

    public function store(int $userId, string $countryCode): void
    {
        if (Country::tryFrom($countryCode) === null) {
            return;
        }

        ($this->writeUserPreference)($userId, ['country_code' => $countryCode]);

        $this->events->dispatch(new UserCountryChanged($userId, $countryCode));
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
    // enum's ISO-code order, with no search box to recover with. Collated rather
    // than compared, or every accented name sorts after Z.
    /**
     * @param  array<string, string>  $countries
     */
    private function sortByLabel(array &$countries): void
    {
        $collator = $this->collator();

        uasort($countries, $collator instanceof Collator
            ? static function (string $a, string $b) use ($collator): int {
                $order = $collator->compare($a, $b);

                // compare() answers false on a collation failure; treating that
                // as "equal" keeps the sort stable rather than ordering on junk.
                return $order === false ? 0 : $order;
            }
            : static fn (string $a, string $b): int => strcasecmp(self::fold($a), self::fold($b)));
    }

    // The mobile build's ext-intl carries English-only ICU data, so asking for
    // any other locale throws on device — the same constraint Fmt::number
    // works around. A null here is that case, not an error.
    private function collator(): ?Collator
    {
        try {
            return Collator::create($this->translator->getLocale());
        } catch (IntlException|\ValueError|\Error) {
            return null;
        }
    }

    // Without ICU, fold the accents onto their base letters so a name at least
    // lands beside its own initial rather than after every unaccented one.
    private static function fold(string $label): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);

        return $folded === false ? $label : $folded;
    }
}
