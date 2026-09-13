<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// Today is a Friday in June; the September 2026 strip runs Mon 31 Aug — Sun 4 Oct
// and the August one Mon 27 Jul — Sun 6 Sep, so 31 August is the September
// grid's FIRST cell and an ordinary mid-strip cell of the August grid.
const FGRID_TODAY = '2026-06-12';

const FGRID_SHARED_CELL = '2026-08-31';

const FGRID_OPENING_MINOR = 50_000;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(FGRID_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'fgrid',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function fgridAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'FGRID Bank',
        'slug' => 'fgrid-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00FGRID'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => FGRID_OPENING_MINOR,
        'opening_balance_as_of_date' => '2026-06-01',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

// One point per day for a year, which is the shape ForecastQuery hands back:
// a projection states a balance for every day it reaches, not only for the
// days something is expected to move on.
function fgridDailyForecast(DatabaseManager $db, int $userId, int $accountId, int $fromMinor): void
{
    $points = [];
    $cursor = CarbonImmutable::parse(FGRID_TODAY);
    for ($day = 0; $day <= 365; $day++) {
        $points[] = [
            'date' => $cursor->toDateString(),
            'low_minor' => $fromMinor - $day,
            'point_minor' => $fromMinor - $day,
            'high_minor' => $fromMinor - $day,
            'currency' => 'EUR',
        ];
        $cursor = $cursor->addDay();
    }

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => 365,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => FGRID_TODAY,
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'FGRID Bank',
                    'default_currency' => 'EUR',
                    'today_balance_minor' => $fromMinor,
                    'points' => $points,
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => FGRID_TODAY.' 00:00:00',
        'updated_at' => FGRID_TODAY.' 00:00:00',
    ]);
}

function fgridDayOn(User $user, int $year, int $month, string $date, int $accountId): CalendarDayDto
{
    /** @var list<CalendarDayDto> $days */
    $days = app(CalendarQuery::class)->forMonth($user, $year, $month, null, [$accountId]);

    foreach ($days as $day) {
        if ($day->date->toDateString() === $date) {
            return $day;
        }
    }

    throw new RuntimeException($date.' is not a cell of the '.$year.'-'.$month.' grid');
}

// The day panel prints a start of day and an end of day for whichever cell was
// clicked. Read from August the cell stated both; read from September — where
// it is the first cell and the actuals overlay stops months short of it — the
// start of day was reported unknown for a figure the projection states.
it('states one start of day for a date whichever month grid drew it', function (): void {
    $accountId = fgridAccount($this->db, $this->user->id);
    fgridDailyForecast($this->db, $this->user->id, $accountId, FGRID_OPENING_MINOR);

    $inAugust = fgridDayOn($this->user, 2026, 8, FGRID_SHARED_CELL, $accountId);
    $inSeptember = fgridDayOn($this->user, 2026, 9, FGRID_SHARED_CELL, $accountId);

    expect($inSeptember->sodBalanceMinor)->toBe(
        $inAugust->sodBalanceMinor,
        'the September grid opens on '.var_export($inSeptember->sodBalanceMinor, true)
        .' where the August grid states '.var_export($inAugust->sodBalanceMinor, true),
    );
});

// The figure itself, not merely agreement: 31 August opens on what 30 August
// closed on, which is the projection's own point for that day.
it('opens the first cell of a future grid on the day before it closing', function (): void {
    $accountId = fgridAccount($this->db, $this->user->id);
    fgridDailyForecast($this->db, $this->user->id, $accountId, FGRID_OPENING_MINOR);

    $first = fgridDayOn($this->user, 2026, 9, FGRID_SHARED_CELL, $accountId);
    $dayBefore = fgridDayOn($this->user, 2026, 8, '2026-08-30', $accountId);

    expect($first->sodBalanceMinor)->toBe($dayBefore->eodBalanceMinor);
});

// The honest null survives: where the projection holds no point for the day
// before the first cell there is no opening to state, and a fabricated 0 is
// what the em-dash exists to avoid.
it('still reports an unknown start of day where the projection reaches no further back', function (): void {
    $accountId = fgridAccount($this->db, $this->user->id);

    $this->db->connection()->table('forecast_runs')->insert([
        'user_id' => $this->user->id,
        'scenario_id' => null,
        'horizon_days' => 365,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => FGRID_TODAY,
            'accounts' => [
                (string) $accountId => [
                    'account_id' => $accountId,
                    'account_name' => 'FGRID Bank',
                    'default_currency' => 'EUR',
                    'today_balance_minor' => FGRID_OPENING_MINOR,
                    'points' => [[
                        'date' => '2026-09-05',
                        'low_minor' => 40_000,
                        'point_minor' => 40_000,
                        'high_minor' => 40_000,
                        'currency' => 'EUR',
                    ]],
                ],
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => FGRID_TODAY.' 00:00:00',
        'updated_at' => FGRID_TODAY.' 00:00:00',
    ]);

    expect(fgridDayOn($this->user, 2026, 9, FGRID_SHARED_CELL, $accountId)->sodBalanceMinor)->toBeNull();
});
