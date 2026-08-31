<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;
use Modules\Recurring\Public\Actions\EditRecurringSeriesName;
use Modules\Recurring\Public\Actions\EditRecurringSeriesVarianceTolerance;
use Modules\Recurring\Public\Actions\SetDriftThresholdForSeries;
use Modules\Recurring\Public\Actions\SnoozeRecurringSeries;
use Modules\Sync\Public\Events\EntityMutated;

// Every column of recurring_series that moves after the insert: the reader's
// name, tolerance and threshold, the state machine, and the detector's own
// metric refresh. A write site that does not emit is invisible to the peer and
// silent on the device that made it.

function eseUser(): User
{
    return User::query()->create([
        'username' => 'series-capture-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'recurring_detection_window_months' => 36,
    ]);
}

function eseCharge(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $postedAt, int $amountMinor): void
{
    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $run->id,
        'type' => 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Spotify',
        'counterparty_normalized' => 'spotify',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => crc32($postedAt) % 100000,
        'fingerprint' => hash('sha256', 'series-capture-'.$postedAt.'-'.bin2hex(random_bytes(4))),
        'fingerprint_version' => 3,
        'created_at' => $postedAt.' 12:00:00',
        'updated_at' => $postedAt.' 12:00:00',
    ]);
}

// A real listener rather than Event::fake(): the state machine and the detector
// are singletons built during the fixture, so they hold the dispatcher instance
// they were constructed with and a fake swapped in afterwards never sees them.
/**
 * @return ArrayObject<int, EntityMutated>
 */
function eseRecord(): ArrayObject
{
    /** @var ArrayObject<int, EntityMutated> $captured */
    $captured = new ArrayObject;

    app(Dispatcher::class)->listen(
        EntityMutated::class,
        static function (EntityMutated $event) use ($captured): void {
            $captured[] = $event;
        },
    );

    return $captured;
}

/**
 * @param  ArrayObject<int, EntityMutated>  $captured
 * @return list<string> the columns captured for recurring_series, deduplicated and sorted
 */
function eseCapturedFields(ArrayObject $captured, int $seriesId): array
{
    $fields = [];

    foreach ($captured as $event) {
        if ($event->table !== 'recurring_series' || (int) $event->pk !== $seriesId) {
            continue;
        }

        foreach (array_keys($event->dirtyFields) as $field) {
            $fields[] = (string) $field;
        }
    }

    $fields = array_values(array_unique($fields));
    sort($fields);

    return $fields;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = eseUser();
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'series capture asn',
        'slug' => 'series-capture-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/series-capture.csv',
        'sha256' => hash('sha256', 'series-capture-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    foreach (['2026-02-04', '2026-03-04', '2026-04-04'] as $postedAt) {
        eseCharge($this->db, $this->user, $this->account, $this->run, $postedAt, -1099);
    }

    $this->app->make(ExpenseSeriesDetector::class)->detectForUser($this->user);
    $this->seriesId = (int) $this->db->connection()->table('recurring_series')->where('user_id', $this->user->id)->value('id');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('captures the reader renaming a series', function (): void {
    $captured = eseRecord();

    app(EditRecurringSeriesName::class)($this->seriesId, $this->user, 'Music');

    expect(eseCapturedFields($captured, $this->seriesId))->toBe(['display_name_override']);
});

it('captures the reader widening the variance tolerance', function (): void {
    $captured = eseRecord();

    app(EditRecurringSeriesVarianceTolerance::class)($this->seriesId, $this->user, 50);

    expect(eseCapturedFields($captured, $this->seriesId))->toBe(['variance_tolerance_percent']);
});

it('captures the reader setting a per-series drift threshold', function (): void {
    $captured = eseRecord();

    app(SetDriftThresholdForSeries::class)($this->seriesId, $this->user, 10);

    expect(eseCapturedFields($captured, $this->seriesId))->toBe(['drift_threshold_percent']);
});

it('captures a state transition and the column that rides with it', function (): void {
    $captured = eseRecord();

    app(SnoozeRecurringSeries::class)($this->seriesId, $this->user, CarbonImmutable::parse('2026-06-17 12:00:00'));

    expect(eseCapturedFields($captured, $this->seriesId))->toBe(['snoozed_until', 'state']);
});

// The detector rewrites the metrics on every sweep, on every device. Uncaptured,
// a phone that had not swept since the price changed showed last month's amount
// on a row the desktop had already refreshed.
it('captures the detector refreshing a series it has already seen', function (): void {
    eseCharge($this->db, $this->user, $this->account, $this->run, '2026-05-04', -1199);

    $captured = eseRecord();

    $this->app->make(ExpenseSeriesDetector::class)->detectForUser($this->user);

    $fields = eseCapturedFields($captured, $this->seriesId);

    expect($fields)->toContain('latest_amount_minor')
        ->and($fields)->toContain('cluster_key')
        ->and($fields)->not->toContain('updated_at');
});
