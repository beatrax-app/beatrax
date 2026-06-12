<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;

/**
 * Public facade for the tax-country selection flow.
 *
 * Exposes "which countries can be chosen" + "select a country for a user"
 * to other modules (the Onboarding setup wizard's tax-country step) without
 * letting them reach into Modules\Tax\Internal — the module boundary arch
 * tests forbid cross-module Internal imports.
 *
 * `selectCountry` mirrors the behaviour of the settings-page country picker
 * (TaxSettingsSection::setTaxCountry, D-07 / T-07-12):
 *   - codes outside the allow-list are silently ignored,
 *   - the corpus seed is INSERT-only / additive — switching country adds new
 *     categories and never deletes or renames existing ones,
 *   - users.tax_country_code is persisted last so a corpus failure never
 *     leaves a country set without its categories.
 */
final class TaxCountrySetup
{
    /**
     * Allow-listed country codes with their display labels — the same
     * list and labels rendered by the settings-page country picker.
     *
     * @var array<string, string>
     */
    private const COUNTRIES = [
        'nl' => 'Netherlands',
        'de' => 'Germany',
        'be' => 'Belgium',
        'fr' => 'France',
        'gb' => 'United Kingdom',
        'us' => 'United States',
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly TaxCategoryWriter $writer,
    ) {}

    /**
     * The selectable tax countries, keyed by lowercase ISO-3166 alpha-2
     * code, in the canonical display order.
     *
     * @return array<string, string> code => display label
     */
    public function availableCountries(): array
    {
        return self::COUNTRIES;
    }

    /**
     * The user's current tax country code, or the empty string when unset.
     */
    public function currentCountry(int $userId): string
    {
        /** @var mixed $code */
        $code = $this->db->connection()
            ->table('users')
            ->where('id', $userId)
            ->value('tax_country_code');

        return is_string($code) ? $code : '';
    }

    /**
     * Set the user's tax country and seed the corpus deduction categories
     * for it. Additive — never deletes existing categories or tags. Codes
     * outside the allow-list (and unknown user ids) are silently ignored.
     */
    public function selectCountry(int $userId, string $countryCode): void
    {
        if (! array_key_exists($countryCode, self::COUNTRIES)) {
            // Out-of-allow-list codes are silently ignored (T-07-12).
            return;
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return;
        }

        // Seed the country's categories (INSERT-only, never deletes).
        $this->writer->seedFromCorpus($user, $countryCode);

        // Persist the preference.
        $this->db->connection()->table('users')
            ->where('id', $userId)
            ->update([
                'tax_country_code' => $countryCode,
                'updated_at' => $this->clock->now()->toDateTimeString(),
            ]);
    }
}
