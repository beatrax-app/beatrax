<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Bus;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Services\ForecastQuery;
use Modules\Forecasting\Tests\Support\ForecastCorpus;

// DailyFold's integer FX rounding is the only slack left once every expected
// triple is derived from the documented arithmetic rather than approximated.
const FPCT_TOLERANCE_MINOR = 5;

/**
 * @return array<string, array{0: string}>
 */
function fpctFixtures(): array
{
    return [
        'stable-monthly-subscription' => ['stable-monthly-subscription'],
        'drifting-subscription-midwindow' => ['drifting-subscription-midwindow'],
        'salary-and-side-income' => ['salary-and-side-income'],
        'multi-account-baseline' => ['multi-account-baseline'],
        'fx-only-usd-subscription' => ['fx-only-usd-subscription'],
        'zero-occurrence-edge-case' => ['zero-occurrence-edge-case'],
        // Exercises ShortfallDetector and buffer-band semantics end-to-end.
        'buffer-crossing' => ['buffer-crossing'],
        // variable-utility's variance_tolerance_percent=45 plus 8 observed
        // occurrences is what trips the percentile-tier branch. Scenario
        // application itself is covered by ScenarioIsolationContractTest.
        'variable-utility' => ['variable-utility'],
        'scenario-with-each-mutation-kind' => ['scenario-with-each-mutation-kind'],
        // Holds a booked future-dated row beside the series that predicted it,
        // so the certainty and the estimate it retires are both in view.
        'booked-future-row' => ['booked-future-row'],
    ];
}

function fpctSeededAt(): string
{
    return ForecastCorpus::clock()->toDateTimeString();
}

function fpctUser(): User
{
    return User::query()->create([
        'username' => 'fpct-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function fpctMapFixtureKindToDbKind(string $fixtureKind): string
{
    return match ($fixtureKind) {
        'asn' => 'asn',
        'ics' => 'ics_card',
        default => $fixtureKind,
    };
}

/**
 * @param  array{date: string, amount_minor: int, currency: string, settled_amount_minor: int, settled_currency: string, fx_rate_used: mixed, counterparty: string, type: string}  $row
 */
function fpctInsertTransaction(DatabaseManager $db, User $user, int $accountId, int $importRunId, array $row): int
{
    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'import_run_id' => $importRunId,
        'fingerprint' => hash('sha256', $accountId.'-'.$row['date'].'-'.bin2hex(random_bytes(8))),
        'posted_at' => $row['date'],
        'booked_at' => $row['date'].' 00:00:00',
        'value_date' => $row['date'],
        'amount_minor' => $row['amount_minor'],
        'currency' => $row['currency'],
        'settled_amount_minor' => $row['settled_amount_minor'],
        'settled_currency' => $row['settled_currency'],
        'fx_rate_used' => $row['fx_rate_used'],
        'counterparty_normalized' => fpctClusterKey($row['counterparty']),
        'counterparty_name' => $row['counterparty'],
        'normalization_version' => 1,
        'description' => $row['counterparty'].' '.$row['date'],
        'type' => $row['type'],
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => fpctSeededAt(),
        'updated_at' => fpctSeededAt(),
    ]);
}

// Both sides of the cluster join RecurringSeriesQuery falls back on for a row
// no detection sweep has linked yet: recurring_series.cluster_counterparty_key
// and transactions.counterparty_normalized have to derive the same way.
function fpctClusterKey(string $counterparty): string
{
    return strtolower($counterparty);
}

/**
 * @return array{accountIdMap: array<int, int>, fixture: array<string, mixed>}
 */
function fpctSeedFixture(DatabaseManager $db, User $user, string $fixtureName): array
{
    $fixturePath = ForecastCorpus::path($fixtureName);
    /** @var array{accounts: list<array<string, mixed>>, series: list<array<string, mixed>>, expected: array<string, mixed>} $fixture */
    $fixture = require $fixturePath;

    $accountIdMap = [];
    foreach ($fixture['accounts'] as $account) {
        $fixtureAccountId = is_numeric($account['id']) ? (int) $account['id'] : 0;
        $kind = fpctMapFixtureKindToDbKind((string) ($account['kind'] ?? 'asn'));
        $uniqueSuffix = bin2hex(random_bytes(6));
        $slug = 'fpct-'.$fixtureName.'-'.$fixtureAccountId.'-'.$uniqueSuffix;
        $iban = 'NL00FPCT'.strtoupper($uniqueSuffix);
        $dbAccountId = $db->connection()->table('accounts')->insertGetId([
            'user_id' => $user->id,
            'name' => (string) ($account['name'] ?? 'Test'),
            'slug' => $slug,
            'kind' => $kind,
            'iban' => $iban,
            'default_currency' => (string) ($account['default_currency'] ?? 'EUR'),
            'opening_balance_minor' => $account['opening_balance_minor'] ?? null,
            'opening_balance_as_of_date' => $account['opening_balance_as_of_date'] ?? null,
            'forecast_min_buffer_minor' => $account['forecast_min_buffer_minor'] ?? null,
            'created_at' => fpctSeededAt(),
            'updated_at' => fpctSeededAt(),
        ]);
        $accountIdMap[$fixtureAccountId] = $dbAccountId;
    }

    // Create one shared import_run for the whole fixture so transactions can FK to it.
    $importRunId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/fpct-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'fpct-'.bin2hex(random_bytes(8))),
        'uploaded_at' => fpctSeededAt(),
        'status' => 'previewed',
        'created_at' => fpctSeededAt(),
        'updated_at' => fpctSeededAt(),
    ]);

    foreach ($fixture['series'] as $series) {
        $fixtureAccountId = is_numeric($series['account_id']) ? (int) $series['account_id'] : 0;
        $dbAccountId = $accountIdMap[$fixtureAccountId] ?? null;
        if ($dbAccountId === null) {
            continue;
        }

        $seriesName = (string) ($series['name'] ?? 'unnamed');
        $direction = (string) ($series['direction'] ?? 'expense');
        $cadence = (string) ($series['cadence'] ?? 'monthly');
        $latestAmount = (int) ($series['latest_amount_minor'] ?? 0);
        $latestCurrency = (string) ($series['latest_currency'] ?? 'EUR');
        $variance = (int) ($series['variance_tolerance_percent'] ?? 25);
        $state = (string) ($series['state'] ?? 'approved');
        $nextExpected = isset($series['next_expected_date']) && is_string($series['next_expected_date']) && $series['next_expected_date'] !== ''
            ? $series['next_expected_date']
            : null;
        $latestFxRate = $series['latest_fx_rate_used'] ?? null;

        $clusterKey = 'fpct-cluster-'.$fixtureName.'-'.bin2hex(random_bytes(4));

        $seriesDbId = $db->connection()->table('recurring_series')->insertGetId([
            'user_id' => $user->id,
            'direction' => $direction,
            'detected_name' => $seriesName,
            'state' => $state,
            'cadence' => $cadence,
            'latest_amount_minor' => $latestAmount,
            'latest_currency' => $latestCurrency,
            'latest_fx_rate_used' => $latestFxRate,
            'monthly_equivalent_minor' => $latestAmount,
            'variance_tolerance_percent' => $variance,
            'next_expected_at' => $nextExpected,
            'next_expected_confidence_low' => false,
            'cluster_key' => $clusterKey,
            'cluster_counterparty_key' => fpctClusterKey($seriesName),
            'created_at' => fpctSeededAt(),
            'updated_at' => fpctSeededAt(),
        ]);

        // Each occurrence needs a backing transaction row: the series-to-account
        // mapping resolves through recurring_series_occurrences.transaction_id.
        $occurrences = is_array($series['occurrences'] ?? null) ? $series['occurrences'] : [];
        foreach ($occurrences as $occ) {
            $occDate = (string) ($occ['date'] ?? '2026-05-01');
            $occAmount = (int) ($occ['observed_amount_minor'] ?? $latestAmount);
            $occCurrency = (string) ($occ['observed_currency'] ?? $latestCurrency);
            $occFxRate = $occ['fx_rate_used'] ?? null;

            $transactionId = fpctInsertTransaction($db, $user, $dbAccountId, $importRunId, [
                'date' => $occDate,
                'amount_minor' => $occAmount,
                'currency' => $occCurrency,
                'settled_amount_minor' => $occCurrency === 'EUR' ? $occAmount : (is_numeric($occFxRate) ? (int) round($occAmount * (float) $occFxRate) : $occAmount),
                'settled_currency' => 'EUR',
                'fx_rate_used' => $occFxRate,
                'counterparty' => $seriesName,
                'type' => $direction,
            ]);

            $db->connection()->table('recurring_series_occurrences')->insert([
                'user_id' => $user->id,
                'recurring_series_id' => $seriesDbId,
                'transaction_id' => $transactionId,
                'observed_at' => $occDate,
                'observed_amount_minor' => $occAmount,
                'observed_currency' => $occCurrency,
                'created_at' => fpctSeededAt(),
                'updated_at' => fpctSeededAt(),
            ]);
        }
    }

    $bookedRows = is_array($fixture['booked_rows'] ?? null) ? $fixture['booked_rows'] : [];
    foreach ($bookedRows as $bookedRow) {
        if (! is_array($bookedRow)) {
            continue;
        }
        $dbAccountId = $accountIdMap[(int) ($bookedRow['account_id'] ?? 0)] ?? null;
        if ($dbAccountId === null) {
            continue;
        }

        $minor = (int) ($bookedRow['settled_amount_minor'] ?? 0);
        $currency = (string) ($bookedRow['settled_currency'] ?? 'EUR');
        fpctInsertTransaction($db, $user, $dbAccountId, $importRunId, [
            'date' => (string) ($bookedRow['date'] ?? ''),
            'amount_minor' => $minor,
            'currency' => $currency,
            'settled_amount_minor' => $minor,
            'settled_currency' => $currency,
            'fx_rate_used' => null,
            'counterparty' => (string) ($bookedRow['counterparty'] ?? ''),
            'type' => (string) ($bookedRow['direction'] ?? 'expense'),
        ]);
    }

    return ['accountIdMap' => $accountIdMap, 'fixture' => $fixture];
}

it('projects the fixture corpus subset end-to-end and matches expected.projection within ±2 minor', function (string $fixtureName): void {
    // The fixture's expected.projection values are calibrated off the accounts'
    // opening_balance_as_of_date, so the clock has to be frozen to that same
    // anchor for the pipeline's asOf to line up.
    CarbonImmutable::setTestNow(ForecastCorpus::clock());

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var ForecastQuery $forecastQuery */
    $forecastQuery = $this->app->make(ForecastQuery::class);

    $user = fpctUser();
    $seeded = fpctSeedFixture($db, $user, $fixtureName);

    /** @var array<int, int> $accountIdMap */
    $accountIdMap = $seeded['accountIdMap'];
    $fixture = $seeded['fixture'];

    // Dispatch synchronously so the projection actually runs (Bus::fake
    // would only capture the dispatch without invoking handle()).
    Bus::dispatchSync(new ProjectForecastJob(userId: $user->id, scenarioId: null, horizonDays: 30));
    Bus::dispatchSync(new ProjectForecastJob(userId: $user->id, scenarioId: null, horizonDays: 60));
    Bus::dispatchSync(new ProjectForecastJob(userId: $user->id, scenarioId: null, horizonDays: 90));

    $expectedProjection = is_array($fixture['expected']['projection'] ?? null) ? $fixture['expected']['projection'] : [];

    foreach ($expectedProjection as $expected) {
        if (! is_array($expected)) {
            continue;
        }
        $horizonDays = (int) ($expected['horizon_days'] ?? 30);
        $fixtureAccountId = (int) ($expected['account_id'] ?? 0);
        $dbAccountId = $accountIdMap[$fixtureAccountId] ?? null;
        if ($dbAccountId === null) {
            continue;
        }

        $dto = $forecastQuery->forUser($dbAccountId, $horizonDays, null, $user);
        expect($dto->isComputing)->toBeFalse();

        $matchDate = (string) ($expected['date'] ?? '');
        $expectedLow = (int) ($expected['low_minor'] ?? 0);
        $expectedPoint = (int) ($expected['point_minor'] ?? 0);
        $expectedHigh = (int) ($expected['high_minor'] ?? 0);

        $matched = null;
        foreach ($dto->points as $point) {
            if ($point->date === $matchDate) {
                $matched = $point;
                break;
            }
        }
        expect($matched)->not->toBeNull(
            "fixture '{$fixtureName}' horizon {$horizonDays}: missing day {$matchDate} in projection",
        );
        if ($matched === null) {
            continue;
        }
        expect(abs($matched->lowMinor - $expectedLow))->toBeLessThanOrEqual(
            FPCT_TOLERANCE_MINOR,
            "fixture '{$fixtureName}' day {$matchDate}: low {$matched->lowMinor} vs expected {$expectedLow}",
        );
        expect(abs($matched->pointMinor - $expectedPoint))->toBeLessThanOrEqual(
            FPCT_TOLERANCE_MINOR,
            "fixture '{$fixtureName}' day {$matchDate}: point {$matched->pointMinor} vs expected {$expectedPoint}",
        );
        expect(abs($matched->highMinor - $expectedHigh))->toBeLessThanOrEqual(
            FPCT_TOLERANCE_MINOR,
            "fixture '{$fixtureName}' day {$matchDate}: high {$matched->highMinor} vs expected {$expectedHigh}",
        );
    }

    // Deliberately tolerant: only that at least one window row exists for the
    // (account, buffer_used_minor) pair. Daily-fold and envelope timing can shift
    // a window boundary by a day, so the start/end dates are not asserted.
    $expectedShortfalls = is_array($fixture['expected']['shortfalls'] ?? null) ? $fixture['expected']['shortfalls'] : [];
    foreach ($expectedShortfalls as $expectedSf) {
        if (! is_array($expectedSf)) {
            continue;
        }
        $fixtureAccountId = (int) ($expectedSf['account_id'] ?? 0);
        $dbAccountId = $accountIdMap[$fixtureAccountId] ?? null;
        if ($dbAccountId === null) {
            continue;
        }
        $row = $db->connection()->table('forecast_shortfall_windows')
            ->where('user_id', $user->id)
            ->where('account_id', $dbAccountId)
            ->whereNull('scenario_id')
            ->where('buffer_used_minor', (int) ($expectedSf['buffer_used_minor'] ?? 0))
            ->orderByDesc('id')
            ->first();
        expect($row)->not->toBeNull(
            "fixture '{$fixtureName}' expected at least one forecast_shortfall_windows row with buffer={$expectedSf['buffer_used_minor']}",
        );
    }

    CarbonImmutable::setTestNow();
})->with(fpctFixtures());
