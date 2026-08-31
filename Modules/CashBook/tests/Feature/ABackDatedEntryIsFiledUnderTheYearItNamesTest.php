<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Services\TaxTagQuery;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'cashbook-backdated',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    // January, so the entered December date and the wall clock fall in
    // different years — the whole defect is invisible in any other month.
    $this->now = CarbonImmutable::create(2026, 1, 5, 9, 30, 0);
    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn($this->now);
    $this->app->instance(Clock::class, $clock);
});

function recordBackDatedCashEntry(User $user, string $day = '2025-12-15'): int
{
    /** @var RecordManualTransaction $record */
    $record = app(RecordManualTransaction::class);
    $record(
        $user,
        Direction::Expense->value,
        12500,
        CarbonImmutable::parse($day)->startOfDay(),
        'December market',
    );

    return (int) DB::table('transactions')
        ->where('user_id', $user->id)
        ->where('source_format', 'manual')
        ->value('id');
}

it('books the entry on the day the reader entered, not the day they typed it', function (): void {
    recordBackDatedCashEntry($this->user);

    $row = DB::table('transactions')->where('user_id', $this->user->id)->first(['posted_at', 'booked_at', 'value_date']);

    expect(substr((string) $row->booked_at, 0, 10))->toBe('2025-12-15')
        ->and(substr((string) $row->posted_at, 0, 10))->toBe('2025-12-15')
        ->and(substr((string) $row->value_date, 0, 10))->toBe('2025-12-15');
});

// Every tax surface attributes a row by booked_at, so a booked_at holding the
// wall clock filed the December receipt under the following year and left it
// out of the year the reader was about to file.
it('files the December entry under the tax year it names', function (): void {
    $txId = recordBackDatedCashEntry($this->user);

    app(TagTransaction::class)->execute($this->user->id, $txId, null, null, null);

    /** @var TaxTagQuery $tags */
    $tags = app(TaxTagQuery::class);

    expect($tags->summaryForUser($this->user->id, 2025)->count)->toBe(1)
        ->and($tags->summaryForUser($this->user->id, 2025)->totalMinor)->toBe(12500)
        ->and($tags->summaryForUser($this->user->id, 2026)->count)->toBe(0);
});

// The cash book prints posted_at and the tax cockpit prints booked_at. One row
// read 15 Dec 2025 on one screen and 05 Jan 2026 on the other.
it('prints one date for the entry on both screens', function (): void {
    recordBackDatedCashEntry($this->user);

    $row = DB::table('transactions')->where('user_id', $this->user->id)->first(['posted_at', 'booked_at']);

    expect(substr((string) $row->booked_at, 0, 10))->toBe(substr((string) $row->posted_at, 0, 10));
});

// The bookedAt offset that breaks a same-second fingerprint collision has to
// keep working off the entered day rather than the wall clock.
it('still records two identical back-dated entries without dropping one', function (): void {
    recordBackDatedCashEntry($this->user);
    recordBackDatedCashEntry($this->user);

    $rows = DB::table('transactions')
        ->where('user_id', $this->user->id)
        ->where('source_format', 'manual')
        ->get(['booked_at']);

    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect(substr((string) $row->booked_at, 0, 10))->toBe('2025-12-15');
    }
});

// The retry offset walks booked_at forward a second at a time. Started from
// the wall clock's own time of day, an entry added at 23:59:59 on New Year's
// Eve walked into the next day — and, on that date, the next tax year.
it('keeps the last-second entry on the day it names, retries and all', function (): void {
    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 3, 4, 23, 59, 59));
    $this->app->instance(Clock::class, $clock);

    recordBackDatedCashEntry($this->user, '2025-12-31');
    recordBackDatedCashEntry($this->user, '2025-12-31');

    $rows = DB::table('transactions')
        ->where('user_id', $this->user->id)
        ->where('source_format', 'manual')
        ->get(['booked_at']);

    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect(substr((string) $row->booked_at, 0, 10))->toBe('2025-12-31');
    }
});
