<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency as CurrencyRow;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// The stranded-plan repair merges a row into the genesis row it would collide
// with, and it did so with `assigned_minor + N` over a pair it had never
// looked at the currency of. `envelope_assignments.currency` is stamped by
// EnvelopeWriter at write time from the reader's base currency, so the two
// months either side of a currency change genuinely hold different codes:
// USD 100.00 merged into EUR 100.00 came out as 20000 labelled EUR.
// EnvelopePeriodRekeyer::totalled() merges the same pair and its own comment
// names this bug; the migration is the sibling that never asked.

function runTheStrandedPlanRepairAcrossCurrencies(): void
{
    $migration = require base_path('Modules/Budgets/Database/Migrations/2026_08_28_000002_lift_envelope_rows_stranded_below_genesis.php');
    $migration->up();
}

function seedCrossCurrencyAssignment(int $userId, int $categoryId, string $periodStart, int $minor, string $currency): void
{
    DB::table('envelope_assignments')->insert([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'period_start' => $periodStart,
        'assigned_minor' => $minor,
        'currency' => $currency,
        'created_at' => '2026-06-20 09:00:00',
        'updated_at' => '2026-06-20 09:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 12:00:00'));

    CurrencyRow::query()->updateOrInsert(['code' => Currency::Eur->value], ['minor_unit' => 2]);
    CurrencyRow::query()->updateOrInsert(['code' => Currency::Usd->value], ['minor_unit' => 2]);

    // The rate table is emptied first: a bundled rate for the same pair would
    // decide the arithmetic this test is asserting the exact answer of.
    DB::table('exchange_rates')->delete();

    $this->user = User::create([
        'username' => 'cross-currency-lift-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 15,
        'default_currency_view' => 'eur_only',
        'base_currency' => Currency::Eur->value,
    ]);
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-06-20 09:00:00']);
    $this->user->refresh();
    $this->actingAs($this->user);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'xcur-g-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    $this->genesis = app(CarryoverQuery::class)->genesisPeriodFor($this->user);
    $this->stranded = app(PeriodQuery::class)->previous($this->genesis)->start->toDateString();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// EUR base, USD quote at 1.25 exactly, so a dollar is worth 0.80 euro and the
// converted half is a whole number of cents rather than a rounding to argue
// about: USD 100.00 is EUR 80.00, and the merged envelope holds EUR 180.00.
it('converts a stranded month written in another currency before it merges it', function (): void {
    DB::table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Usd->value,
        'rate_date' => '2026-06-01',
        'rate' => '1.25000',
        'source' => 'ecb',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    seedCrossCurrencyAssignment($this->user->id, $this->groceries->id, $this->stranded, 10000, Currency::Usd->value);
    seedCrossCurrencyAssignment($this->user->id, $this->groceries->id, $this->genesis->start->toDateString(), 10000, Currency::Eur->value);

    runTheStrandedPlanRepairAcrossCurrencies();

    $rows = DB::table('envelope_assignments')->where('user_id', $this->user->id)->get();

    expect($rows)->toHaveCount(1);
    // `assigned_minor + 10000` answered 20000 here — an envelope reading
    // EUR 200.00 where the reader assigned EUR 100.00 and USD 100.00.
    expect((int) $rows[0]->assigned_minor)->toBe(18000);
    expect((string) $rows[0]->currency)->toBe(Currency::Eur->value);
    expect((string) $rows[0]->period_start)->toBe($this->genesis->start->toDateString());
});

// The rekeyer's own fallback, for the same reason: dropping the half with no
// rate would delete stored money that comes back the day a rate arrives, and
// the fold already reads a row it cannot price as zero wherever it sits.
it('leaves a pair the rate table cannot price summed as it was', function (): void {
    seedCrossCurrencyAssignment($this->user->id, $this->groceries->id, $this->stranded, 10000, Currency::Usd->value);
    seedCrossCurrencyAssignment($this->user->id, $this->groceries->id, $this->genesis->start->toDateString(), 10000, Currency::Eur->value);

    runTheStrandedPlanRepairAcrossCurrencies();

    $rows = DB::table('envelope_assignments')->where('user_id', $this->user->id)->get();

    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]->assigned_minor)->toBe(20000);
    expect((string) $rows[0]->currency)->toBe(Currency::Eur->value);
});

// One currency on both sides is the ordinary case and must not go near a rate:
// the fixture above only evidences the conversion if this one still adds.
it('adds two months written in the same currency without converting either', function (): void {
    seedCrossCurrencyAssignment($this->user->id, $this->groceries->id, $this->stranded, 40000, Currency::Eur->value);
    seedCrossCurrencyAssignment($this->user->id, $this->groceries->id, $this->genesis->start->toDateString(), 15000, Currency::Eur->value);

    runTheStrandedPlanRepairAcrossCurrencies();

    $rows = DB::table('envelope_assignments')->where('user_id', $this->user->id)->get();

    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]->assigned_minor)->toBe(55000);
    expect((string) $rows[0]->currency)->toBe(Currency::Eur->value);
});
