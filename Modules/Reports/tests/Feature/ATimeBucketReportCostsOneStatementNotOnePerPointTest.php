<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\ReportMetric;
use Modules\Reports\Internal\Aggregation\SpendFilterApplier;
use Modules\Reports\Internal\Aggregation\SpendQueryFilters;
use Modules\Reports\Internal\Aggregation\TimeBucketSpendQuery;
use Modules\Reports\Internal\Enums\ReportGranularity;

uses(RefreshDatabase::class);

// The point count is the reader's choice of period and granularity, not a
// property of the ledger, so it is what the statement count must not follow.

$tbcUser = static function (string $username): User {
    /** @var User */
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
};

$tbcLedger = static function (ConnectionInterface $conn, User $user): void {
    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'tbc-asn-'.$user->id,
        'kind' => 'bank',
        'iban' => 'NL00TBC'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
    ]);

    $runId = $conn->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tbc-'.$user->id.'.csv',
        'sha256' => hash('sha256', 'tbc-'.$user->id),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $batch = [];
    // Two rows in most months and none at all in March, so the zero a bucket
    // with no row reports is exercised beside the sums.
    foreach (range(1, 12) as $month) {
        if ($month === 3) {
            continue;
        }

        foreach ([3, 17] as $index => $day) {
            $date = CarbonImmutable::create(2026, $month, $day)->toDateString();
            $batch[] = [
                'user_id' => $user->id,
                'account_id' => $account->id,
                'import_run_id' => $runId,
                'fingerprint' => hash('sha256', 'tbc-'.$user->id.'-'.$month.'-'.$day),
                'posted_at' => $date,
                'booked_at' => $date.' 00:00:00',
                'value_date' => $date,
                'amount_minor' => -100 * ($month + $index),
                'currency' => 'EUR',
                'settled_amount_minor' => -100 * ($month + $index),
                'settled_currency' => 'EUR',
                'counterparty_normalized' => 'tbc-m'.$month,
                'counterparty_name' => 'Merchant '.$month,
                'normalization_version' => 1,
                'description' => 'tbc row '.$month.'-'.$day,
                'type' => 'expense',
                'source_format' => 'asn-csv',
                'source_row_index' => $month * 10 + $index,
                'fingerprint_version' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    $conn->table('transactions')->insert($batch);
};

$tbcStatements = static function (callable $run): int {
    $statements = 0;
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements++;
    });

    $run();

    return $statements;
};

it('reads a twelve-point and a thirteen-point report in one statement each', function () use ($tbcUser, $tbcLedger, $tbcStatements): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $user = $tbcUser('tbc-flat');
    $tbcLedger($manager->connection(), $user);

    /** @var TimeBucketSpendQuery $query */
    $query = app(TimeBucketSpendQuery::class);

    $monthly = [];
    $monthlyCost = $tbcStatements(function () use ($query, $user, &$monthly): void {
        $monthly = $query->forUserAndPeriod(
            $user,
            new Period(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2027, 1, 1), 'year'),
            'spend',
            'EUR',
            ReportGranularity::Monthly,
        );
    });

    $weekly = [];
    $weeklyCost = $tbcStatements(function () use ($query, $user, &$weekly): void {
        $weekly = $query->forUserAndPeriod(
            $user,
            new Period(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 4, 1), 'quarter'),
            'spend',
            'EUR',
            ReportGranularity::Weekly,
        );
    });

    expect($monthly)->toHaveCount(12)
        ->and($monthlyCost)->toBe(1)
        ->and($weekly)->toHaveCount(13)
        ->and($weeklyCost)->toBe(1);
});

// The one bucket with no row must still be a point on the chart, at nought.
it('reports nought for a bucket the ledger has no row in', function () use ($tbcUser, $tbcLedger): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $user = $tbcUser('tbc-gap');
    $tbcLedger($manager->connection(), $user);

    /** @var TimeBucketSpendQuery $query */
    $query = app(TimeBucketSpendQuery::class);

    $rows = $query->forUserAndPeriod(
        $user,
        new Period(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2027, 1, 1), 'year'),
        'spend',
        'EUR',
        ReportGranularity::Monthly,
    );

    expect($rows[0]->groupKey)->toBe('2026-01-01')
        ->and($rows[0]->amountMinor)->toBe(300)
        ->and($rows[2]->groupKey)->toBe('2026-03-01')
        ->and($rows[2]->amountMinor)->toBe(0)
        ->and($rows[11]->amountMinor)->toBe(2500);
});

// The per-bucket aggregate this replaced, run here so the grouped statement is
// held to the figure it has to keep producing rather than to a written-down one.
it('totals every bucket to what one aggregate per bucket answered', function () use ($tbcUser, $tbcLedger): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $conn = $manager->connection();
    $user = $tbcUser('tbc-parity');
    $tbcLedger($conn, $user);

    /** @var TimeBucketSpendQuery $query */
    $query = app(TimeBucketSpendQuery::class);
    /** @var SpendFilterApplier $applier */
    $applier = app(SpendFilterApplier::class);

    $period = new Period(CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2027, 1, 1), 'year');
    $filters = new SpendQueryFilters(accountIds: [], categoryIds: []);

    foreach (['spend', 'income', 'net'] as $metric) {
        $metricEnum = ReportMetric::fromMetric($metric);
        $rows = $query->forUserAndPeriod($user, $period, $metric, 'EUR', ReportGranularity::Monthly, $filters);

        $perBucket = [];
        foreach ($rows as $row) {
            $start = CarbonImmutable::parse($row->groupKey);
            $aggregate = $conn->table('transactions')
                ->where('user_id', $user->id)
                ->whereRaw(...$metricEnum->predicate())
                ->where('settled_currency', 'EUR')
                ->where('posted_at', '>=', $start->toDateString())
                ->where('posted_at', '<', $start->addMonthNoOverflow()->toDateString())
                ->tap(fn (QueryBuilder $q): QueryBuilder => $applier->apply($q, $filters))
                ->selectRaw($applier->amountExpr($metricEnum, $filters).' AS amount_minor')
                ->first();

            $perBucket[] = (int) ($aggregate->amount_minor ?? 0);
        }

        expect(array_map(static fn ($row): int => $row->amountMinor, $rows))->toBe($perBucket);
    }
});
