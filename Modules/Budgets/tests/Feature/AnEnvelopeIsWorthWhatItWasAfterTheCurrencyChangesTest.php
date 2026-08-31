<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 09:00:00'));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Usd->value,
        'rate_date' => '2026-07-01',
        'rate' => '1.13590',
        'source' => 'ecb',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    $this->user = User::create([
        'username' => 'envelope-ccy-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Usd->value,
    ]);
    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-06-01 00:00:00']);
    $this->user->refresh();
    $this->actingAs($this->user);

    $this->rent = Category::create(['user_id' => null, 'name' => 'Rent', 'slug' => 'ccy-rent-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 1]);

    $this->june = app(PeriodQuery::class)->containingDate('2026-06-01');
    $this->july = app(PeriodQuery::class)->containingDate('2026-07-01');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function switchReportingCurrencyTo(string $code): void
{
    DB::table('users')->where('id', test()->user->id)->update(['base_currency' => $code]);
    test()->user->refresh();
    test()->actingAs(test()->user);
}

function assignedRead(object $period): int
{
    return app(CarryoverQuery::class)->forUserAndPeriod(test()->user, $period)['rows'][test()->rent->id]->assignedMinor;
}

// The read path converts a row into the reader's currency; the writer used to
// hand the raw minor on and stamp the new code beside it, so one click turned
// USD 500.00 into EUR 500.00 on every envelope of the month.
it('copies last month at what last month is worth, not at its raw number', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->rent->id, $this->june->start, 50000);

    switchReportingCurrencyTo(Currency::Eur->value);
    expect(assignedRead($this->june))->toBe(44018);

    app(EnvelopeWriter::class)->copyFromPeriod($this->user, $this->june, $this->july);

    expect(assignedRead($this->july))->toBe(44018);

    $copied = DB::table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('period_start', $this->july->start->toDateString())
        ->first(['assigned_minor', 'currency']);
    expect((int) $copied->assigned_minor)->toBe(44018);
    expect((string) $copied->currency)->toBe(Currency::Eur->value);
});

it('leaves the month it copied from exactly as it was', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->rent->id, $this->june->start, 50000);

    switchReportingCurrencyTo(Currency::Eur->value);
    app(EnvelopeWriter::class)->copyFromPeriod($this->user, $this->june, $this->july);

    $source = DB::table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('period_start', $this->june->start->toDateString())
        ->first(['assigned_minor', 'currency']);
    expect((int) $source->assigned_minor)->toBe(50000);
    expect((string) $source->currency)->toBe(Currency::Usd->value);
});

// Relabelling is the one thing that must never happen: with no rate to convert
// at, the row travels in the currency it was written in and the fold surfaces
// it the same way it surfaced the source.
it('carries an envelope it has no rate for across in its own currency', function (): void {
    DB::table('envelope_assignments')->insert([
        'user_id' => $this->user->id,
        'category_id' => $this->rent->id,
        'period_start' => $this->june->start->toDateString(),
        'assigned_minor' => 50000,
        'currency' => 'ZAR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    app(EnvelopeWriter::class)->copyFromPeriod($this->user, $this->june, $this->july);

    $copied = DB::table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('period_start', $this->july->start->toDateString())
        ->first(['assigned_minor', 'currency']);
    expect((int) $copied->assigned_minor)->toBe(50000);
    expect((string) $copied->currency)->toBe('ZAR');
});

// The grid seeds the cell with the figure it printed. Re-typing it after a
// currency switch sends the same minor under a different sign, and the write
// used to be dropped as "no change" — silently, beside a cell now disagreeing
// with the row it sits in.
it('takes an edit that only changes the currency the amount is in', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->rent->id, $this->july->start, 50000);

    switchReportingCurrencyTo(Currency::Eur->value);
    expect(assignedRead($this->july))->toBe(44018);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->rent->id, $this->july->start, 50000);

    expect(assignedRead($this->july))->toBe(50000);
    expect((string) DB::table('envelope_assignments')->where('user_id', $this->user->id)->value('currency'))
        ->toBe(Currency::Eur->value);
});

it('still writes nothing when neither the amount nor the currency moved', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->rent->id, $this->july->start, 50000);
    $before = DB::table('envelope_assignments')->where('user_id', $this->user->id)->first(['id', 'updated_at']);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-11 09:00:00'));
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->rent->id, $this->july->start, 50000);

    $after = DB::table('envelope_assignments')->where('user_id', $this->user->id)->first(['id', 'updated_at']);
    expect($after->id)->toBe($before->id);
    expect($after->updated_at)->toBe($before->updated_at);
});
