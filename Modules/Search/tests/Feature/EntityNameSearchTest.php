<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Search\Internal\Services\EntityNameSearch;

// Covers EntityNameSearch across every palette entity type — categories,
// goals, pots, recurring series — plus the counterparty branches, so each
// name-only lookup path is exercised end to end.

function entityDb(): ConnectionInterface
{
    return app(DatabaseManager::class)->connection();
}

function entityUser(string $username): User
{
    $id = entityDb()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return User::findOrFail($id);
}

function entityAccount(User $user): int
{
    $suffix = bin2hex(random_bytes(4));

    return entityDb()->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Entity ASN '.$suffix,
        'slug' => 'entity-asn-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('returns nothing for an empty query', function (): void {
    $user = entityUser('entity-empty');

    expect(app(EntityNameSearch::class)->query($user, ''))->toBe([]);
});

it('finds a category the user owns and a global seeded category', function (): void {
    $user = entityUser('entity-cat');

    entityDb()->table('categories')->insert([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'slug' => 'groceries-'.$user->id,
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    entityDb()->table('categories')->insert([
        'user_id' => null,
        'name' => 'Groceries Global',
        'slug' => 'groceries-global',
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hits = collect(app(EntityNameSearch::class)->query($user, 'grocer'))
        ->where('type', 'category')
        ->pluck('label');

    expect($hits->all())->toContain('Groceries')->toContain('Groceries Global');
});

it('finds a goal by name and links to the goals page', function (): void {
    $user = entityUser('entity-goal');

    entityDb()->table('goals')->insert([
        'user_id' => $user->id,
        'name' => 'Holiday Fund',
        'target_minor' => 500000,
        'target_currency' => 'EUR',
        'start_date' => '2026-01-01',
        'target_date' => '2026-12-31',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hit = collect(app(EntityNameSearch::class)->query($user, 'holiday'))
        ->firstWhere('type', 'goal');

    expect($hit)->not->toBeNull()
        ->and($hit['label'])->toBe('Holiday Fund')
        ->and($hit['url'])->toBe('/goals');
});

it('finds a pot by name and links to the pots page', function (): void {
    $user = entityUser('entity-pot');
    $accountId = entityAccount($user);

    entityDb()->table('pots')->insert([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'name' => 'Rainy Day',
        'currency' => 'EUR',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hit = collect(app(EntityNameSearch::class)->query($user, 'rainy'))
        ->firstWhere('type', 'pot');

    expect($hit)->not->toBeNull()
        ->and($hit['label'])->toBe('Rainy Day')
        ->and($hit['url'])->toBe('/pots');
});

it('finds a recurring series, preferring the display-name override over the detected name', function (): void {
    $user = entityUser('entity-recurring');

    $overrideId = entityDb()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'NFLX DIGITAL NL',
        'display_name_override' => 'Netflix Subscription',
        'latest_amount_minor' => -1599,
        'latest_currency' => 'EUR',
        'cluster_key' => 'netflix-'.$user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hit = collect(app(EntityNameSearch::class)->query($user, 'netflix'))
        ->firstWhere('type', 'recurring');

    expect($hit)->not->toBeNull()
        ->and($hit['label'])->toBe('Netflix Subscription')
        ->and($hit['url'])->toBe('/recurring/series/'.$overrideId);
});

it('matches a recurring series on its detected name when there is no override', function (): void {
    $user = entityUser('entity-recurring-detected');

    entityDb()->table('recurring_series')->insert([
        'user_id' => $user->id,
        'direction' => 'income',
        'detected_name' => 'Acme Salary',
        'display_name_override' => null,
        'latest_amount_minor' => 250000,
        'latest_currency' => 'EUR',
        'cluster_key' => 'acme-'.$user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hit = collect(app(EntityNameSearch::class)->query($user, 'acme'))
        ->firstWhere('type', 'recurring');

    expect($hit)->not->toBeNull()->and($hit['label'])->toBe('Acme Salary');
});

it('matches a counterparty by display name but skips non-matching rows', function (): void {
    $user = entityUser('entity-cp');

    entityDb()->table('counterparties')->insert([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'albert-heijn',
        'display_name' => 'Albert Heijn',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    entityDb()->table('counterparties')->insert([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'jumbo',
        'display_name' => 'Jumbo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hits = collect(app(EntityNameSearch::class)->query($user, 'albert'))
        ->where('type', 'counterparty');

    expect($hits->pluck('label')->all())->toBe(['Albert Heijn'])
        ->and($hits->firstWhere('label', 'Albert Heijn')['url'])->toBe('/counterparties/albert-heijn');
});
