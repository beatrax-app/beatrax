<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Tests\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

// Replays a corpus fixture the way production reaches the evaluator: each
// transaction lands, becomes an occurrence, and the evaluator runs for the
// series before the next one arrives. Evaluating once at the end would only
// ever compare the newest pair, so a fixture expecting two alerts across four
// postings could not be expressed at all.
final class DriftCorpusSeeder
{
    /**
     * @param  array<string, mixed>  $fixture
     * @return int the recurring_series id the fixture drives
     */
    public static function replay(DatabaseManager $db, User $user, array $fixture, DriftEvaluator $evaluator): int
    {
        $connection = $db->connection();
        /** @var list<array<string, mixed>> $transactions */
        $transactions = is_array($fixture['transactions'] ?? null) ? $fixture['transactions'] : [];
        /** @var array<string, mixed> $expected */
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];

        $globalThreshold = $expected['user_drift_threshold_percent'] ?? null;
        if (is_int($globalThreshold)) {
            $user->forceFill(['drift_alert_threshold_percent' => $globalThreshold])->save();
        }

        $salt = bin2hex(random_bytes(4));
        $accountId = $connection->table('accounts')->insertGetId([
            'user_id' => $user->id,
            'name' => 'ASN corpus',
            'slug' => 'drift-corpus-'.$salt,
            'kind' => 'bank',
            'iban' => 'NL00DRIFT'.substr(bin2hex(random_bytes(8)), 0, 9),
            'default_currency' => 'EUR',
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
        $runId = $connection->table('import_runs')->insertGetId([
            'user_id' => $user->id,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/drift-corpus-'.$salt.'.csv',
            'sha256' => hash('sha256', 'drift-corpus'.$salt),
            'uploaded_at' => '2026-05-19 00:00:00',
            'status' => 'previewed',
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);

        $seriesId = self::insertSeries($db, $user, $fixture, $salt);

        $index = 0;
        foreach ($transactions as $transaction) {
            $index++;
            $txId = self::insertTransaction($db, $user, $accountId, $runId, $transaction, $salt, $index);
            self::insertOccurrence($db, $user, $seriesId, $txId, $transaction);
            $evaluator->evaluateForSeries($seriesId, $user);
        }

        return $seriesId;
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private static function insertSeries(DatabaseManager $db, User $user, array $fixture, string $salt): int
    {
        /** @var list<array<string, mixed>> $transactions */
        $transactions = is_array($fixture['transactions'] ?? null) ? $fixture['transactions'] : [];
        /** @var array<string, mixed> $expected */
        $expected = is_array($fixture['expected'] ?? null) ? $fixture['expected'] : [];
        $first = $transactions[0] ?? [];

        $type = is_string($first['type'] ?? null) ? $first['type'] : TransactionType::Expense->value;
        $currency = $expected['series_currency'] ?? ($first['original_currency'] ?? $first['currency'] ?? 'EUR');
        $cadence = $expected['series_cadence'] ?? SeriesCadence::Monthly->value;
        $state = $expected['series_state'] ?? RecurringSeriesState::Approved->value;
        $latestMinor = $first['original_amount_minor'] ?? $first['amount_minor'] ?? 0;

        return $db->connection()->table('recurring_series')->insertGetId([
            'user_id' => $user->id,
            'direction' => TransactionType::directionOf($type)->value,
            'detected_name' => is_string($first['counterparty_normalized'] ?? null) ? $first['counterparty_normalized'] : 'corpus-merchant',
            'state' => $state,
            'cadence' => $cadence,
            'latest_amount_minor' => $latestMinor,
            'latest_currency' => $currency,
            'variance_tolerance_percent' => 25,
            'cluster_key' => 'drift-corpus|'.$salt,
            'drift_threshold_percent' => $expected['series_drift_threshold_percent'] ?? null,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private static function insertTransaction(
        DatabaseManager $db,
        User $user,
        int $accountId,
        int $runId,
        array $transaction,
        string $salt,
        int $index,
    ): int {
        $postedAt = is_string($transaction['posted_at'] ?? null) ? $transaction['posted_at'] : '2026-01-01';
        $counterparty = is_string($transaction['counterparty_normalized'] ?? null) ? $transaction['counterparty_normalized'] : 'corpus-merchant';

        return $db->connection()->table('transactions')->insertGetId([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', $salt.'-'.$index),
            'posted_at' => $postedAt,
            'booked_at' => $postedAt.' 00:00:00',
            'value_date' => $postedAt,
            'amount_minor' => $transaction['amount_minor'] ?? 0,
            'currency' => $transaction['currency'] ?? 'EUR',
            'settled_amount_minor' => $transaction['amount_minor'] ?? 0,
            'settled_currency' => $transaction['currency'] ?? 'EUR',
            'counterparty_normalized' => $counterparty,
            'counterparty_name' => mb_strtoupper($counterparty),
            'counterparty_iban' => $transaction['counterparty_iban'] ?? null,
            'normalization_version' => 1,
            'description' => 'drift corpus '.$salt,
            'type' => $transaction['type'] ?? TransactionType::Expense->value,
            'source_format' => 'asn-csv',
            'source_row_index' => $index,
            'fingerprint_version' => 3,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    }

    // The occurrence records the ORIGINAL amount and currency, never the
    // settled pair: comparing settled figures would make every rate move look
    // like a price move, which is the invariant fx-only-swing pins.
    /**
     * @param  array<string, mixed>  $transaction
     */
    private static function insertOccurrence(DatabaseManager $db, User $user, int $seriesId, int $txId, array $transaction): void
    {
        $postedAt = is_string($transaction['posted_at'] ?? null) ? $transaction['posted_at'] : '2026-01-01';

        $db->connection()->table('recurring_series_occurrences')->insert([
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'transaction_id' => $txId,
            'observed_at' => $postedAt,
            'observed_amount_minor' => $transaction['original_amount_minor'] ?? $transaction['amount_minor'] ?? 0,
            'observed_currency' => $transaction['original_currency'] ?? $transaction['currency'] ?? 'EUR',
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    }
}
