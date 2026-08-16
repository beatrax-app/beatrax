<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Support\Lang;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;
use Modules\Tax\Public\Enums\TaxCountry;

/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class TaxCountrySetup
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly WriteUserPreference $writeUserPreference,
        private readonly TaxCategoryWriter $writer,
    ) {}

    // Derived from TaxCountry, never a second hand-maintained list: the literal
    // copy that used to live here disagreed with the enum the moment a
    // country's corpus file landed, leaving onboarding on six countries while
    // Settings offered twenty-six.

    // Labels come from the same translation group the settings picker reads,
    // so the two surfaces cannot drift apart again.
    /**
     * @return array<string, string> code => display label
     */
    public function availableCountries(): array
    {
        $countries = [];
        foreach (TaxCountry::cases() as $country) {
            $countries[$country->value] = Lang::get('tax::settings.countries.'.$country->value);
        }

        return $countries;
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
