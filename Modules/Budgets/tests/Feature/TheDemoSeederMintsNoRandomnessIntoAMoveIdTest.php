<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Database\Seeders\Demo\DemoEnvelopeBudgetsSeeder;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\CategoryKind;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// Every device seeds the same demo moves. While the group id was a fresh uuid
// on each and the row id was the next autoincrement, the two devices wrote one
// demo move twice under one id, and only the discard of the arriving create
// hid it.

function demoIdCategories(DatabaseManager $db): void
{
    $order = 10;

    foreach (['transport-fuel', 'eating-out', 'housing-utilities', 'personal-care'] as $slug) {
        $db->connection()->table('categories')->insert([
            'user_id' => null,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'kind' => CategoryKind::Expense->value,
            'display_order' => $order += 10,
            'created_at' => '2026-05-01 00:00:00',
            'updated_at' => '2026-05-01 00:00:00',
        ]);
    }
}

function demoIdUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('derives the same move ids every time it seeds the same demo move', function (): void {
    CarbonImmutable::setTestNow('2026-05-14 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    demoIdCategories($db);
    $user = demoIdUser('demo-determinism');

    app(DemoEnvelopeBudgetsSeeder::class)->run(['demo-determinism' => $user]);
    $first = $db->connection()->table('envelope_moves')->orderBy('id')->pluck('id')->all();

    // The seeder skips a move it can already see, so the rows go before the
    // second run — the question is whether it re-derives the same ids, which a
    // uuid minted per run could not.
    $db->connection()->table('envelope_moves')->delete();
    CarbonImmutable::setTestNow('2026-05-22 17:30:00');

    app(DemoEnvelopeBudgetsSeeder::class)->run(['demo-determinism' => $user]);
    $second = $db->connection()->table('envelope_moves')->orderBy('id')->pluck('id')->all();

    expect($first)->not->toBe([])->and($second)->toBe($first);
});

it('gives two demo users on one device ids of their own', function (): void {
    CarbonImmutable::setTestNow('2026-05-14 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    demoIdCategories($db);

    $one = demoIdUser('demo-one');
    $two = demoIdUser('demo-two');

    app(DemoEnvelopeBudgetsSeeder::class)->run(['demo-one' => $one, 'demo-two' => $two]);

    $ids = $db->connection()->table('envelope_moves')->pluck('id')->all();

    expect($ids)->toHaveCount(8)
        ->and(array_unique($ids))->toHaveCount(8);
});
