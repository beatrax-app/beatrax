<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'assign-window-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

function assignWindowUser(int $periodStartDay, string $baseCurrency = 'EUR'): User
{
    return User::create([
        'username' => 'assign-window-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => $periodStartDay,
        'default_currency_view' => 'eur_only',
        'base_currency' => $baseCurrency,
    ]);
}

it('stores a date that is not a period boundary on the period that contains it', function (): void {
    $user = assignWindowUser(15);
    $this->actingAs($user);
    $period = app(PeriodQuery::class)->current();

    app(EnvelopeWriter::class)->setAssigned($user, $this->groceries->id, $period->start->addDays(5), 50000);

    expect(DB::table('envelope_assignments')->where('user_id', $user->id)->value('period_start'))
        ->toBe($period->start->toDateString());
});

it('reads a mid-period write back on the budgets grid rather than as nothing', function (): void {
    $user = assignWindowUser(15);
    $this->actingAs($user);
    $period = app(PeriodQuery::class)->current();

    app(EnvelopeWriter::class)->setAssigned($user, $this->groceries->id, $period->start->addDays(5), 50000);

    $rows = app(CarryoverQuery::class)->forUserAndPeriod($user, $period)['rows'];

    expect($rows[$this->groceries->id]->assignedMinor)->toBe(50000);
});

it('leaves a caller that already passes its own period start exactly where it was', function (): void {
    $user = assignWindowUser(1);
    $this->actingAs($user);
    $period = app(PeriodQuery::class)->current();

    app(EnvelopeWriter::class)->setAssigned($user, $this->groceries->id, $period->start, 12345);

    expect(DB::table('envelope_assignments')->where('user_id', $user->id)->value('period_start'))
        ->toBe($period->start->toDateString());
});

it('copies last month forward onto the target period it was handed', function (): void {
    $user = assignWindowUser(15);
    $this->actingAs($user);
    $periods = app(PeriodQuery::class);
    $current = $periods->current();
    $previous = $periods->previous($current);

    app(EnvelopeWriter::class)->setAssigned($user, $this->groceries->id, $previous->start, 7000);
    app(EnvelopeWriter::class)->copyFromPeriod($user, $previous, $current);

    expect(DB::table('envelope_assignments')->where('user_id', $user->id)->orderBy('period_start')->pluck('period_start')->all())
        ->toBe([$previous->start->toDateString(), $current->start->toDateString()]);
});

// "The user's base currency" and "the user's period" both used to be read off
// whoever the guard carried, which is a different question from the one a
// writer handed the owner explicitly is answering.
it('keys the row to the owner rather than to whoever is browsing', function (): void {
    Currency::query()->updateOrInsert(['code' => 'USD'], ['name' => 'US dollar', 'minor_unit' => 2]);

    $owner = assignWindowUser(15, 'USD');
    $browser = assignWindowUser(1);

    $this->actingAs($owner);
    $periods = app(PeriodQuery::class);
    $sourceDate = $periods->current()->start->startOfMonth();
    $ownersPeriod = $periods->containingDate($sourceDate->toDateString());

    $this->actingAs($browser);
    app(EnvelopeWriter::class)->setAssigned($owner, $this->groceries->id, $sourceDate, 2500);

    $row = DB::table('envelope_assignments')->where('user_id', $owner->id)->first();

    expect($ownersPeriod)->not->toBeNull()
        ->and($ownersPeriod->start->toDateString())->not->toBe($sourceDate->toDateString())
        ->and($row)->not->toBeNull()
        ->and($row->period_start)->toBe($ownersPeriod->start->toDateString())
        ->and($row->currency)->toBe('USD');
});
