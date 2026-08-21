<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Country;
use Modules\Core\Public\Services\UserCountry;

function userCountryFixture(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function storedCountryCode(int $userId): mixed
{
    return app(DatabaseManager::class)->connection()
        ->table('users')
        ->where('id', $userId)
        ->value('country_code');
}

it('offers every allow-listed country with a display label', function (): void {
    $available = app(UserCountry::class)->options();

    // Asserted against the enum, not a literal list: a copy of the allow-list
    // here would only ever fail on the day someone adds a country.
    $expectedCodes = array_map(
        static fn (Country $case): string => $case->value,
        Country::cases(),
    );

    // Compared as a set: the list is ordered by the label the reader scans,
    // which is not the enum's ISO-code order and differs per locale.
    $offeredCodes = array_keys($available);
    sort($offeredCodes);
    sort($expectedCodes);

    expect($offeredCodes)->toBe($expectedCodes)
        ->and($available)->each->toBeString();

    expect($available['nl'])->toBe('Netherlands')
        ->and($available['gb'])->toBe('United Kingdom');
});

// The list is scanned by eye and has no search box, so the only order that helps
// is the one the reader sees.
it('orders the countries by the name shown, not by their code', function (): void {
    $labels = array_values(app(UserCountry::class)->options());

    expect($labels[0])->toBe(min($labels));
    expect(array_search('Switzerland', $labels, true))
        ->toBeGreaterThan(array_search('Spain', $labels, true));
});

it('backs every offered country with a corpus file that actually loads', function (): void {
    // An allow-listed country with no corpus behind it puts an empty
    // jurisdiction in the picker: selectable, then nothing to tag.
    $empty = [];
    foreach (array_keys(app(UserCountry::class)->options()) as $code) {
        if (! is_file(base_path("resources/corpus/tax/{$code}.yaml"))) {
            $empty[] = $code;
        }
    }

    expect($empty)->toBe([], 'these countries are offered with no corpus: '.implode(', ', $empty));
});

it('stores the choice on users.country_code and seeds the deduction corpus behind it', function (): void {
    $user = userCountryFixture('user-country-01');

    app(UserCountry::class)->store($user->id, 'nl');

    expect(storedCountryCode($user->id))->toBe('nl');

    $categoryCount = app(DatabaseManager::class)->connection()
        ->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();

    expect($categoryCount)->toBeGreaterThan(0);
});

it('silently ignores a code outside the allow-list', function (): void {
    $user = userCountryFixture('user-country-02');

    app(UserCountry::class)->store($user->id, 'xx');

    expect(storedCountryCode($user->id))->toBeNull();

    $categoryCount = app(DatabaseManager::class)->connection()
        ->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->count();

    expect($categoryCount)->toBe(0);
});

it('is additive when switching countries — earlier categories survive', function (): void {
    $user = userCountryFixture('user-country-03');
    $countries = app(UserCountry::class);

    $countries->store($user->id, 'nl');

    $nlCount = app(DatabaseManager::class)->connection()
        ->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();
    expect($nlCount)->toBeGreaterThan(0);

    $countries->store($user->id, 'de');

    expect(storedCountryCode($user->id))->toBe('de');

    $nlAfter = app(DatabaseManager::class)->connection()
        ->table('tax_deduction_categories')
        ->where('user_id', $user->id)
        ->where('country_code', 'nl')
        ->count();
    expect($nlAfter)->toBe($nlCount);
});

// Unset is a state the classification relies on: it widens to every region
// rather than pinning the install to a guess.
it('answers the empty string while nothing is chosen and the stored code after', function (): void {
    $user = userCountryFixture('user-country-04');
    $countries = app(UserCountry::class);

    expect($countries->current($user->id))->toBe('');

    $countries->store($user->id, 'be');

    expect($countries->current($user->id))->toBe('be');
});
