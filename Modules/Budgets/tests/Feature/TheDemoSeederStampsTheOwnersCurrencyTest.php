<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Database\Seeders\Demo\DemoEnvelopeBudgetsSeeder;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\CategoryKind;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// `demo:seed` is an artisan command, so nothing is authenticated while it
// writes: BaseCurrency::code() answers with config('currency.base') there,
// and the demo user it was handed is the one whose currency the row means.
it('stamps a demo envelope with the demo user’s own currency, not the install default', function (): void {
    CarbonImmutable::setTestNow('2026-05-14 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('categories')->insert([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => CategoryKind::Expense->value,
        'display_order' => 10,
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    /** @var User $user */
    $user = User::query()->create([
        'username' => 'demo-currency',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => Currency::Usd->value,
    ]);

    expect(config('currency.base'))->toBe(Currency::Eur->value);

    app(DemoEnvelopeBudgetsSeeder::class)->run(['demo-currency' => $user]);

    $currencies = $db->connection()->table('envelope_assignments')
        ->where('user_id', $user->id)
        ->distinct()
        ->pluck('currency')
        ->all();

    expect($currencies)->toBe([Currency::Usd->value]);
});
