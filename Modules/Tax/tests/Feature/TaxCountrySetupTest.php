<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Tax\Public\Services\TaxCountrySetup;

/*
 * Feature tests for the TaxCountrySetup public facade — the cross-module
 * surface the Onboarding wizard's tax-country step consumes. Mirrors the
 * TaxSettingsSection country-picker contract (D-07 / T-07-12): allow-listed
 * codes only, additive corpus seeding, users.tax_country_code persistence.
 */

function taxCountrySetupUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('lists the six allow-listed countries with their display labels', function (): void {
    /** @var TaxCountrySetup $setup */
    $setup = app(TaxCountrySetup::class);

    expect($setup->availableCountries())->toBe([
        'nl' => 'Netherlands',
        'de' => 'Germany',
        'be' => 'Belgium',
        'fr' => 'France',
        'gb' => 'United Kingdom',
        'us' => 'United States',
    ]);
});

it('selectCountry persists users.tax_country_code and seeds the corpus categories', function (): void {
    $user = taxCountrySetupUser('tax-setup-01');

    /** @var TaxCountrySetup $setup */
    $setup = app(TaxCountrySetup::class);

    $setup->selectCountry($user->id, 'nl');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $countryCode = $db->connection()->table('users')->where('id', $user->id)->value('tax_country_code');
    expect($countryCode)->toBe('nl');

    $categoryCount = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();
    expect($categoryCount)->toBeGreaterThan(0);
});

it('selectCountry silently ignores a code outside the allow-list', function (): void {
    $user = taxCountrySetupUser('tax-setup-02');

    /** @var TaxCountrySetup $setup */
    $setup = app(TaxCountrySetup::class);

    $setup->selectCountry($user->id, 'xx');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $countryCode = $db->connection()->table('users')->where('id', $user->id)->value('tax_country_code');
    expect($countryCode)->toBeNull();

    $categoryCount = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->count();
    expect($categoryCount)->toBe(0);
});

it('selectCountry is additive when switching countries — earlier categories survive', function (): void {
    $user = taxCountrySetupUser('tax-setup-03');

    /** @var TaxCountrySetup $setup */
    $setup = app(TaxCountrySetup::class);

    $setup->selectCountry($user->id, 'nl');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $nlCount = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();
    expect($nlCount)->toBeGreaterThan(0);

    $setup->selectCountry($user->id, 'de');

    $countryCode = $db->connection()->table('users')->where('id', $user->id)->value('tax_country_code');
    expect($countryCode)->toBe('de');

    // The NL rows must still be there after the switch.
    $nlAfter = $db->connection()->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();
    expect($nlAfter)->toBe($nlCount);
});

it('currentCountry returns the empty string when unset and the stored code when set', function (): void {
    $user = taxCountrySetupUser('tax-setup-04');

    /** @var TaxCountrySetup $setup */
    $setup = app(TaxCountrySetup::class);

    expect($setup->currentCountry($user->id))->toBe('');

    $setup->selectCountry($user->id, 'be');

    expect($setup->currentCountry($user->id))->toBe('be');
});
