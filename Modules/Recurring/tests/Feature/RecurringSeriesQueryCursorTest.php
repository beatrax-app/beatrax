<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

// approvedForUser sorts by monthly_equivalent_minor DESC then id DESC. The
// cursor must preserve that primary sort — the previous page's tail id has to
// yield strictly smaller equivalents, not a disjoint id-window.
function rsqcUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function rsqcSeries(User $user, int $monthlyEquivalentMinor, string $name): RecurringSeries
{
    // 'income' with positive equivalents, so the DESC ordering reads top-down as
    // a literal "largest equivalent first".
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'income',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => $monthlyEquivalentMinor,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => $monthlyEquivalentMinor,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'rsqc::'.$name,
        'cluster_counterparty_key' => $name,
    ]);
}

function rsqcTransaction(DatabaseManager $db, User $user, int $index): int
{
    $account = Account::query()->firstOrCreate(
        ['user_id' => $user->id, 'slug' => 'rsqc-'.$user->id],
        ['name' => 'rsqc account', 'kind' => 'bank', 'iban' => 'NL00RSQC'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT), 'default_currency' => 'EUR'],
    );
    $run = ImportRun::query()->firstOrCreate(
        ['user_id' => $user->id, 'sha256' => str_pad((string) $user->id, 64, 'r', STR_PAD_LEFT)],
        [
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/rsqc.csv',
            'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
            'status' => 'previewed',
        ],
    );

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-0'.$index.'-15',
        'booked_at' => '2026-0'.$index.'-15 12:00:00',
        'value_date' => '2026-0'.$index.'-15',
        'amount_minor' => -1000 * $index,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000 * $index,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Occ Merchant',
        'counterparty_normalized' => 'occ merchant',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $index + ($user->id * 100),
        'fingerprint' => str_pad('occ'.$user->id.'-'.$index, 64, 'o', STR_PAD_LEFT),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = rsqcUser('rsqc');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns approved rows in descending monthly_equivalent order across pages', function (): void {
    // Inserted out of order so id ASC and equivalent DESC do not coincide.
    foreach ([1500, 5000, 800, 3000, 200] as $eq) {
        rsqcSeries($this->user, $eq, 'row-'.$eq);
    }

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    $page1 = $query->approvedForUser($this->user, null, 3);
    expect($page1)->toHaveCount(3);
    $page1Eq = array_map(static fn ($r) => $r->monthlyEquivalent->toMinor(), $page1);
    expect($page1Eq)->toBe([5000, 3000, 1500]);

    $tailId = $page1[count($page1) - 1]->seriesId;
    $page2 = $query->approvedForUser($this->user, $tailId, 3);
    $page2Eq = array_map(static fn ($r) => $r->monthlyEquivalent->toMinor(), $page2);
    expect($page2Eq)->toBe([800, 200]);
})->group('approved-cursor-respects-monthly-equivalent-sort');

it('pendingForUser excludes cadence_changed rows so the Pending tab and the Cadence-changed tab do not double-count', function (): void {
    rsqcSeries($this->user, 1000, 'pending-row');
    /** @var RecurringSeries $pendingRow */
    $pendingRow = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->firstOrFail();
    $this->db->connection()->table('recurring_series')
        ->where('id', $pendingRow->id)
        ->update(['state' => 'pending']);

    rsqcSeries($this->user, 2000, 'cadence-changed-row');
    /** @var RecurringSeries $cadenceRow */
    $cadenceRow = RecurringSeries::query()
        ->where('user_id', $this->user->id)
        ->where('detected_name', 'cadence-changed-row')
        ->firstOrFail();
    $this->db->connection()->table('recurring_series')
        ->where('id', $cadenceRow->id)
        ->update(['state' => 'cadence_changed']);

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);
    $pending = $query->pendingForUser($this->user);
    expect($pending)->toHaveCount(1);
    expect($pending[0]->seriesId)->toBe($pendingRow->id);
    expect($query->pendingCountForUser($this->user))->toBe(1);

    $cadenceChanged = $query->cadenceChangedForUser($this->user);
    expect($cadenceChanged)->toHaveCount(1);
    expect($cadenceChanged[0]->seriesId)->toBe($cadenceRow->id);
})->group('pending-excludes-cadence-changed');

it('amountTrendForSeries returns an empty currency + empty points when the series row carries an empty latest_currency', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Written through the query builder because the model would reject an
    // empty latest_currency; the schema's NULL check still passes on ''.
    $now = '2026-05-17 12:00:00';
    $id = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $this->user->id,
        'direction' => 'expense',
        'detected_name' => 'trend-empty-currency',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => '',
        'monthly_equivalent_minor' => -1099,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'corrupt::empty-currency',
        'cluster_counterparty_key' => 'trend-empty-currency',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    /** @var RecurringOccurrenceQuery $query */
    $query = $this->app->make(RecurringOccurrenceQuery::class);
    $trend = $query->amountTrendForSeries((int) $id, $this->user);

    expect($trend->currency)->toBe('');
    expect($trend->points)->toBe([]);
})->group('amount-trend-empty-currency-returns-empty-payload');

it('amountTrendForSeries defaults to the base currency for a missing series', function (): void {
    /** @var RecurringOccurrenceQuery $query */
    $query = $this->app->make(RecurringOccurrenceQuery::class);
    $trend = $query->amountTrendForSeries(999999, $this->user);

    expect($trend->currency)->toBe('EUR');
    expect($trend->points)->toBe([]);
});

it('paginates correctly when monthly_equivalent values tie — id-tiebreak descends within the tied band', function (): void {
    // Two rows tie on monthly_equivalent, so the cursor has to compose both
    // predicates: equivalent < cursorEq OR (equal AND id < cursorId).
    $a = rsqcSeries($this->user, 1000, 'tie-a');
    $b = rsqcSeries($this->user, 1000, 'tie-b');
    $c = rsqcSeries($this->user, 500, 'low-c');

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    $page1 = $query->approvedForUser($this->user, null, 1);
    expect($page1)->toHaveCount(1);
    expect($page1[0]->seriesId)->toBe($b->id);

    $page2 = $query->approvedForUser($this->user, $b->id, 1);
    expect($page2)->toHaveCount(1);
    expect($page2[0]->seriesId)->toBe($a->id);

    $page3 = $query->approvedForUser($this->user, $a->id, 1);
    expect($page3)->toHaveCount(1);
    expect($page3[0]->seriesId)->toBe($c->id);
})->group('approved-cursor-handles-monthly-equivalent-ties');

// The cursor row was read with no user_id, so another household member's
// monthly_equivalent_minor decided where this reader's page started — a
// binary search on a figure the reader is not allowed to see.
it('ignores a cursor id belonging to another household member', function (): void {
    $other = rsqcUser('rsqc-other');
    $theirs = rsqcSeries($other, 4000, 'their-row');

    foreach ([1500, 5000, 800] as $eq) {
        rsqcSeries($this->user, $eq, 'row-'.$eq);
    }

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    $page = $query->approvedForUser($this->user, $theirs->id, 10);

    expect(array_map(static fn ($r) => $r->monthlyEquivalent->toMinor(), $page))->toBe([5000, 1500, 800]);
});

// "Largest first" on the signed column put the smallest expense at the top,
// because -1000 sorts above -900000 on a plain DESC.
it('puts the biggest expense first rather than the least negative integer', function (): void {
    foreach ([-1000, -900000, -50000] as $eq) {
        RecurringSeries::query()->create([
            'user_id' => $this->user->id,
            'direction' => 'expense',
            'detected_name' => 'exp'.abs($eq),
            'state' => 'approved',
            'cadence' => 'monthly',
            'latest_amount_minor' => $eq,
            'latest_currency' => 'EUR',
            'monthly_equivalent_minor' => $eq,
            'variance_tolerance_percent' => 25,
            'cluster_key' => 'rsqc-exp::'.abs($eq),
            'cluster_counterparty_key' => 'exp'.abs($eq),
        ]);
    }

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    expect(array_map(
        static fn ($r) => $r->monthlyEquivalent->toMinor(),
        $query->approvedForUser($this->user, null, 10),
    ))->toBe([-900000, -50000, -1000]);
});

// DriftEvaluator reads two occurrences and occurrencesForSeries() hydrated
// every one of them to hand over that pair.
it('reads only the newest occurrences a caller asked for', function (): void {
    $series = rsqcSeries($this->user, 1000, 'occ-row');

    foreach (range(1, 6) as $i) {
        $this->db->connection()->table('recurring_series_occurrences')->insert([
            'user_id' => $this->user->id,
            'recurring_series_id' => $series->id,
            'transaction_id' => rsqcTransaction($this->db, $this->user, $i),
            'observed_at' => '2026-0'.$i.'-15',
            'observed_amount_minor' => 1000 * $i,
            'observed_currency' => 'EUR',
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);
    }

    /** @var RecurringOccurrenceQuery $query */
    $query = $this->app->make(RecurringOccurrenceQuery::class);

    $latest = $query->latestOccurrencesForSeries((int) $series->id, $this->user, 2);

    expect($latest)->toHaveCount(2);
    expect(array_map(static fn ($o) => $o->observedAmount->toMinor(), $latest))->toBe([6000, 5000]);
});

it('returns nothing from another household member series occurrences', function (): void {
    $other = rsqcUser('rsqc-occ-other');
    $theirs = rsqcSeries($other, 1000, 'their-occ');

    $this->db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $other->id,
        'recurring_series_id' => $theirs->id,
        'transaction_id' => rsqcTransaction($this->db, $other, 5),
        'observed_at' => '2026-05-15',
        'observed_amount_minor' => 9999,
        'observed_currency' => 'EUR',
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    /** @var RecurringOccurrenceQuery $query */
    $query = $this->app->make(RecurringOccurrenceQuery::class);

    expect($query->latestOccurrencesForSeries((int) $theirs->id, $this->user, 2))->toBe([]);
});

it('answers the drift-threshold override for a page of series in one read', function (): void {
    $withOverride = rsqcSeries($this->user, 1000, 'thr-a');
    $withNone = rsqcSeries($this->user, 900, 'thr-b');
    $other = rsqcSeries(rsqcUser('rsqc-thr-other'), 800, 'thr-c');

    $this->db->connection()->table('recurring_series')
        ->where('id', $withOverride->id)
        ->update(['drift_threshold_percent' => 25]);

    /** @var RecurringSeriesQuery $query */
    $query = $this->app->make(RecurringSeriesQuery::class);

    expect($query->driftThresholdsForSeriesIds([$withOverride->id, $withNone->id, $other->id], $this->user))
        ->toBe([(int) $withOverride->id => 25, (int) $withNone->id => null]);
});
