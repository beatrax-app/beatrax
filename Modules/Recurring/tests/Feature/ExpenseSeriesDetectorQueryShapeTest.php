<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Detectors\ExpenseSeriesDetector;

const ESD_MERCHANTS = 12;

function esdUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'recurring_detection_window_months' => 36,
    ]);
}

function esdAccount(User $user, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'esd '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00ESD'.str_pad(substr($slug, 0, 9), 11, '0', STR_PAD_RIGHT),
        'default_currency' => 'EUR',
    ]);
}

function esdRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/esd.csv',
        'sha256' => str_pad($sha, 64, '0'),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);
}

function esdTx(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $postedAt, int $amountMinor, string $currency, string $normalized, ?string $name, string $type, int $rowIndex, string $seed): int
{
    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => $type,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => $currency,
        'counterparty_name' => $name,
        'counterparty_normalized' => $normalized,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => substr(hash('sha256', $seed), 0, 64),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);
}

/**
 * Twelve merchants across two users, each with a year of monthly charges, plus
 * the shapes the query changes could get wrong: rows older than the detection
 * window, two charges sharing a posted_at (the column the merchant-name lookup
 * orders on), rows with a NULL counterparty_name, a second currency, and
 * pre-existing series in every state the tolerance lookup filters on.
 *
 * @return array{user: User, other: User}
 */
function esdFixture(DatabaseManager $db): array
{
    $user = esdUser('esd-owner');
    $other = esdUser('esd-other');

    $account = esdAccount($user, 'esd-asn');
    $second = esdAccount($user, 'esd-bunq');
    $foreignAccount = esdAccount($other, 'esd-foreign');

    $run = esdRun($user, 'a');
    $otherRun = esdRun($other, 'b');

    $row = 0;
    for ($m = 1; $m <= ESD_MERCHANTS; $m++) {
        $merchant = 'merchant '.$m;
        for ($month = 1; $month <= 12; $month++) {
            $postedAt = CarbonImmutable::parse('2025-05-14')->addMonthsNoOverflow($month)->toDateString();
            // Every fourth merchant leaves counterparty_name NULL, so the
            // display-name lookup has to skip past rows it cannot read.
            $name = $m % 4 === 0 ? null : 'Merchant '.$m.' B.V.';
            esdTx($db, $user, $account, $run, $postedAt, -1000 - $m, 'EUR', $merchant, $name, 'expense', ++$row, 'a'.$m.'-'.$month);
        }

        // A second charge on the same posted_at as the newest one, on a
        // different account and with a different name — a tie the newest-name
        // lookup has to break the same way every run.
        esdTx($db, $user, $second, $run, CarbonImmutable::parse('2025-05-14')->addMonthsNoOverflow(12)->toDateString(), -1000 - $m, 'EUR', $merchant, 'Merchant '.$m.' TIED', 'expense', ++$row, 'tie'.$m);

        // Older than the 36-month window's edge for the last merchant only,
        // and old enough that no cadence can reach it.
        esdTx($db, $user, $account, $run, '2019-01-1'.($m % 9), -1000 - $m, 'EUR', $merchant, 'Merchant '.$m.' ANCIENT', 'expense', ++$row, 'old'.$m);
    }

    // A merchant in a second currency, so the currency scoping has something
    // to scope, and a fee/refund pair which the detector also clusters.
    for ($month = 1; $month <= 6; $month++) {
        $postedAt = CarbonImmutable::parse('2025-11-03')->addMonthsNoOverflow($month)->toDateString();
        esdTx($db, $user, $account, $run, $postedAt, -2500, 'USD', 'usd merchant', 'USD Merchant', 'expense', ++$row, 'usd'.$month);
        esdTx($db, $user, $account, $run, $postedAt, -199, 'EUR', 'bank fees', 'Bank Fees', 'fee', ++$row, 'fee'.$month);
    }

    // The other user's ledger, identical merchant keys — nothing here may
    // reach the owner's clusters.
    for ($month = 1; $month <= 12; $month++) {
        $postedAt = CarbonImmutable::parse('2025-05-14')->addMonthsNoOverflow($month)->toDateString();
        esdTx($db, $other, $foreignAccount, $otherRun, $postedAt, -3300, 'EUR', 'merchant 1', 'Foreign Merchant 1', 'expense', ++$row, 'o'.$month);
    }

    // The user's own naming of one merchant outranks the bank's string.
    $db->connection()->table('merchants')->insert([
        'user_id' => $user->id,
        'name' => 'Merchant Two Renamed',
        'normalized_name' => 'merchant 2',
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ]);

    // Pre-existing series covering every state the tolerance lookup filters
    // on, a user-widened tolerance, a cadence that no longer matches, and the
    // legacy row whose cluster_counterparty_key holds a display name.
    $states = ['pending', 'approved', 'rejected', 'snoozed', 'cadence_changed'];
    foreach ($states as $i => $state) {
        $merchant = 'merchant '.($i + 1);
        $db->connection()->table('recurring_series')->insert([
            'user_id' => $user->id,
            'direction' => 'expense',
            'detected_name' => $merchant,
            'state' => $state,
            'cadence' => $i === 4 ? 'weekly' : 'monthly',
            'latest_amount_minor' => -1000 - ($i + 1),
            'latest_currency' => 'EUR',
            'monthly_equivalent_minor' => -1000 - ($i + 1),
            'variance_tolerance_percent' => $i === 1 ? 60 : 25,
            'cluster_key' => 'expense::merchant-'.($i + 1).'::eur::'.($i === 4 ? 'weekly' : 'monthly'),
            'cluster_counterparty_key' => $i === 3 ? 'Merchant 4 B.V.' : $merchant,
            'next_expected_at' => '2026-06-14',
            'next_expected_confidence_low' => false,
            'created_at' => '2026-05-01 12:00:00',
            'updated_at' => '2026-05-01 12:00:00',
        ]);
    }

    return ['user' => $user, 'other' => $other];
}

/**
 * @return string a dump of everything the sweep is allowed to have written
 */
function esdDump(DatabaseManager $db): string
{
    $series = $db->connection()->table('recurring_series')
        ->orderBy('user_id')->orderBy('direction')->orderBy('cluster_key')->orderBy('id')
        ->get([
            'user_id', 'direction', 'detected_name', 'state', 'cadence',
            'latest_amount_minor', 'latest_currency', 'monthly_equivalent_minor',
            'variance_tolerance_percent', 'next_expected_at', 'next_expected_confidence_low',
            'cluster_key', 'cluster_counterparty_key',
        ])->all();

    $occurrences = $db->connection()->table('recurring_series_occurrences as o')
        ->join('recurring_series as s', 's.id', '=', 'o.recurring_series_id')
        ->join('transactions as t', 't.id', '=', 'o.transaction_id')
        ->orderBy('s.cluster_key')->orderBy('o.observed_at')->orderBy('t.fingerprint')
        ->get([
            's.cluster_key', 'o.user_id', 'o.observed_at',
            'o.observed_amount_minor', 'o.observed_currency', 't.fingerprint',
        ])->all();

    return (string) json_encode(['series' => $series, 'occurrences' => $occurrences], JSON_UNESCAPED_SLASHES);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('writes the identical series and occurrences the per-cluster lookups wrote', function (): void {
    $fixture = esdFixture($this->db);

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    $detector->detectForUser($fixture['user']);
    $detector->detectForUser($fixture['other']);

    $afterFirst = esdDump($this->db);

    // Re-running must be a no-op: the sweep runs on every import.
    $detector->detectForUser($fixture['user']);
    $detector->detectForUser($fixture['other']);

    expect(esdDump($this->db))->toBe($afterFirst);

    $golden = (string) file_get_contents(__DIR__.'/../fixtures/expense-series-sweep.json');
    expect($afterFirst)->toBe(trim($golden));
});

it('reads recurring_series once for the sweep instead of three times per merchant', function (): void {
    $fixture = esdFixture($this->db);

    /** @var ExpenseSeriesDetector $detector */
    $detector = $this->app->make(ExpenseSeriesDetector::class);
    $detector->detectForUser($fixture['user']);

    /** @var list<string> $log */
    $log = [];
    $this->db->connection()->listen(static function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    // The second sweep is the steady state: every series already exists, so
    // nothing is inserted and the only recurring_series read the detector
    // makes is the one index fetch.
    $detector->detectForUser($fixture['user']);

    $seriesReads = array_values(array_filter(
        $log,
        static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "recurring_series"'),
    ));

    // The three per-cluster lookups: cluster_key, cluster_counterparty_key,
    // and the variance tolerance keyed on the same counterparty column. The
    // fixture's 14 clusters cost 42 of them before.
    $perCluster = array_filter(
        $seriesReads,
        static fn (string $sql): bool => str_contains($sql, '"cluster_key" = ?') || str_contains($sql, '"cluster_counterparty_key" = ?'),
    );
    expect($perCluster)->toBe([]);

    $indexReads = array_filter(
        $seriesReads,
        static fn (string $sql): bool => str_contains($sql, '"latest_currency" in'),
    );
    expect($indexReads)->toHaveCount(1);
});

it('seeks the merchant-name lookup on the new index instead of scanning the user partition', function (): void {
    $fixture = esdFixture($this->db);

    $plan = $this->db->connection()->select(
        'EXPLAIN QUERY PLAN select "counterparty_name" from "transactions"'
        .' where "user_id" = ? and "counterparty_normalized" = ? and "counterparty_name" is not null'
        .' order by "posted_at" desc, "id" desc limit 1',
        [$fixture['user']->id, 'merchant 1'],
    );

    $detail = implode(' | ', array_map(static fn (object $row): string => (string) ($row->detail ?? ''), $plan));

    expect($detail)->toContain('transactions_user_counterparty_posted_idx');
    expect($detail)->not->toContain('SCAN transactions');
    expect($detail)->not->toContain('TEMP B-TREE');
});
