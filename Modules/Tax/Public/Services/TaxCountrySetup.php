<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Tax\Internal\Actions\TaxCategoryWriter;

/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class TaxCountrySetup
{
    // Same list and labels rendered by the settings-page country
    // picker.
    /**
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
        private readonly WriteUserPreference $writeUserPreference,
        private readonly TaxCategoryWriter $writer,
    ) {}

    // Keyed by lowercase ISO 3166 alpha-2 code, in the canonical display
    // order.
    /**
     * @return array<string, string> code => display label
     */
    public function availableCountries(): array
    {
        return self::COUNTRIES;
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
        if (! array_key_exists($countryCode, self::COUNTRIES)) {
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
