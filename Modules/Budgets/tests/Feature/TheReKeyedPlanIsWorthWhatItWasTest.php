<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopePeriodRekeyer;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// The genesis floor can land two old periods on one new one, and the surviving
// row carries their sum. Two months written in two currencies summed their
// minor units: a EUR 100.00 month and a USD 100.00 month became a EUR 200.00
// envelope, from one settings save.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);
    Currency::query()->updateOrInsert(['code' => 'USD'], ['name' => 'US dollar', 'minor_unit' => 2]);
    DB::table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    DB::table('exchange_rates')->insert([
        'base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate_date' => '2026-08-01',
        'rate' => '2.0', 'source' => 'ecb',
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);

    $this->user = User::create([
        'username' => 'rekey-ccy-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 15,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);
    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'rekey-ccy-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function rekeyCcyPlanValue(User $user): int
{
    $carry = app(CarryoverQuery::class);
    $periods = app(PeriodQuery::class);
    $genesis = $carry->genesisPeriodFor($user);
    $total = 0;
    $cursor = $genesis;
    for ($i = 0; $i < 12; $i++) {
        foreach ($carry->forUserAndPeriod($user, $cursor)['rows'] as $row) {
            $total += $row->assignedMinor;
        }
        $cursor = $periods->next($cursor);
    }

    return $total;
}

it('is worth the same after the budget month moves, whatever currency each month was written in', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-06-10 09:00:00']);
    $this->user->refresh();

    $writer = app(EnvelopeWriter::class);
    $writer->setAssigned($this->user, $this->groceries->id, CarbonImmutable::parse('2026-05-15'), 10000);

    DB::table('users')->where('id', $this->user->id)->update(['base_currency' => 'USD']);
    $this->user->refresh();
    $writer->setAssigned($this->user, $this->groceries->id, CarbonImmutable::parse('2026-06-15'), 10000);

    DB::table('users')->where('id', $this->user->id)->update(['base_currency' => 'EUR']);
    $this->user->refresh();

    $before = rekeyCcyPlanValue($this->user);

    DB::table('users')->where('id', $this->user->id)->update(['period_start_day' => 28]);
    $this->user->refresh();
    app(EnvelopePeriodRekeyer::class)->rekeyToCurrentPeriods(15);

    expect(DB::table('envelope_assignments')->where('user_id', $this->user->id)->get()->toArray())->toHaveCount(1)
        ->and(rekeyCcyPlanValue($this->user))->toBe($before);
});
