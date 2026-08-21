<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Collator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use IntlException;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Support\Lang;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Modules\Tax\Public\Enums\TaxCountry;

final class TaxCountrySetup
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly WriteUserPreference $writeUserPreference,
        private readonly TaxCategoryWriter $writer,
        private readonly Translator $translator,
    ) {}

    // Derived from TaxCountry, never a second hand-maintained list: the literal
    // copy that used to live here left onboarding on six countries while
    // Settings offered twenty-six.

    // Labels come from the settings picker's own translation group.
    /**
     * @return array<string, string> code => display label
     */
    public function availableCountries(): array
    {
        $countries = [];
        foreach (TaxCountry::cases() as $country) {
            $countries[$country->value] = Lang::get('tax::settings.countries.'.$country->value);
        }

        $this->sortByLabel($countries);

        return $countries;
    }

    // The reader scans the NAMES, so the names decide the order. It was the
    // enum's, which is ISO code order — Switzerland between Canada and Cyprus,
    // Germany before Denmark, the United Kingdom under G — and with no search
    // box, scanning is the only way through a list of thirty-three.
    //
    // Collated, not sorted: a plain comparison puts every accented name after
    // Z, which would simply mis-order the Czech, Greek and Nordic lists instead
    // of the English one.
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

    public function currentCountry(int $userId): string
    {
        /** @var mixed $code */
        $code = $this->db->connection()
            ->table('users')
            ->where('id', $userId)
            ->value('tax_country_code');

        return is_string($code) ? $code : '';
    }

    public function selectCountry(int $userId, string $countryCode): void
    {
        if (TaxCountry::tryFrom($countryCode) === null) {
            return;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return;
        }

        $this->writer->seedFromCorpus($user, $countryCode);

        ($this->writeUserPreference)($userId, ['tax_country_code' => $countryCode]);
    }
}
