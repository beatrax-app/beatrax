<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\Services\TopCategoriesByPeriodQuery;
use Modules\Ledger\Public\Services\TransactionListQuery;

// A Dutch account and a reader who thinks in pounds is an ordinary household,
// and it emptied the app: the period tiles, the top categories and the recent
// list all filtered the ledger down to rows already settled in the reporting
// currency instead of converting it, so every one of them read zero.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->seedFixtureUserAndAccount();
    $this->fixtureUser->base_currency = Currency::Gbp->value;
    $this->fixtureUser->save();
    $this->actingAs($this->fixtureUser);

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Gbp->value,
        'rate_date' => '2026-05-15',
        'rate' => '0.80',
        'source' => 'ecb',
        'created_at' => '2026-05-15 00:00:00',
        'updated_at' => '2026-05-15 00:00:00',
    ]);

    $this->glance = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $this->topCategories = $this->app->make(TopCategoriesByPeriodQuery::class);
    $this->list = $this->app->make(TransactionListQuery::class);
    $this->period = $this->app->make(PeriodQuery::class)->current();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('converts the period tiles rather than counting only the rows already in the reporting currency', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 250000, 'settled_amount_minor' => 250000, 'posted_at' => '2026-05-05',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -5000, 'settled_amount_minor' => -5000, 'posted_at' => '2026-05-08',
    ]);

    $summary = $this->glance->for($this->fixtureUser, $this->period);

    expect($summary->inflow->currency())->toBe(Currency::Gbp->value)
        ->and($summary->inflow->toMinor())->toBe(200000)
        ->and($summary->outflow->toMinor())->toBe(4000)
        ->and($summary->net->toMinor())->toBe(196000);
});

it('converts the income the budget page asks it for', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 250000, 'settled_amount_minor' => 250000, 'posted_at' => '2026-05-05',
    ]);

    expect($this->glance->incomeForPeriod($this->fixtureUser, $this->period))->toBe(200000);
});

it('names a currency it has no rate for instead of adding it in at one to one', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 250000, 'settled_amount_minor' => 250000, 'posted_at' => '2026-05-05',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 111100, 'currency' => 'ZAR', 'settled_amount_minor' => 111100,
        'settled_currency' => 'ZAR', 'posted_at' => '2026-05-06',
    ]);

    $summary = $this->glance->for($this->fixtureUser, $this->period);

    expect($summary->inflow->toMinor())->toBe(200000)
        ->and($summary->unconvertedCurrencies)->toBe(['ZAR']);
});

it('converts the top categories rather than reporting no spend at all', function (): void {
    /** @var Category $category */
    $category = Category::query()->create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
    ]);

    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -5000, 'settled_amount_minor' => -5000,
        'posted_at' => '2026-05-08', 'category_id' => $category->id,
    ]);

    $rows = $this->topCategories->for($this->fixtureUser, $this->period);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->spend->currency())->toBe(Currency::Gbp->value)
        ->and($rows[0]->spend->toMinor())->toBe(4000);
});

it('keeps a row in the list that the reporting currency does not match', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -5000, 'settled_amount_minor' => -5000, 'posted_at' => '2026-05-08',
    ]);

    $page = $this->list->recent($this->fixtureUser, daysBack: 90, limit: 10, currency: Currency::Gbp->value);

    expect($page->rows)->toHaveCount(1);
});
