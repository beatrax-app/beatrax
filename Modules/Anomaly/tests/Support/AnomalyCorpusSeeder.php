<?php

declare(strict_types=1);

namespace Modules\Anomaly\Tests\Support;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/**
 * Test-only helper that materialises an anomaly-corpus fixture
 * (`Modules/Anomaly/tests/fixtures/anomaly-corpus/*.php`) into real
 * `transactions` / `counterparties` / `categories` /
 * `recurring_series(_occurrences)` / `anomaly_suppression_rules` rows so
 * the evaluator and the three detectors run against the same substrate
 * they will read in production.
 *
 * Each fixture declares `settings`, `history`, `transaction`, and
 * `expected`; some add `suppression_rules`. This seeder maps the fixture's
 * counterparty *slugs* onto `counterparties` rows, resolves the optional
 * `category` slug onto `categories`, and seeds every history row plus the
 * single transaction-under-test, returning the under-test transaction id.
 *
 * The seeder is deliberately verbose and explicit (no Eloquent factories)
 * so the FK chain — accounts → import_runs → transactions — is obvious and
 * every UNIQUE constraint (accounts.iban, import_runs.sha256,
 * transactions.fingerprint) gets a salted unique value.
 */
final class AnomalyCorpusSeeder
{
    /**
     * @param  array<string, mixed>  $fixture
     * @return int the transaction id of the charge under test
     */
    public static function seed(DatabaseManager $db, User $user, array $fixture): int
    {
        $connection = $db->connection();

        // Apply per-user anomaly settings.
        /** @var array<string, mixed> $settings */
        $settings = is_array($fixture['settings'] ?? null) ? $fixture['settings'] : [];
        $user->forceFill([
            'anomaly_sensitivity_percent' => (int) ($settings['anomaly_sensitivity_percent'] ?? 50),
            'anomaly_min_amount_minor' => (int) ($settings['anomaly_min_amount_minor'] ?? 1000),
        ])->save();

        // One shared account + import run for the whole fixture.
        $accountId = $connection->table('accounts')->insertGetId([
            'user_id' => $user->id,
            'name' => 'ASN corpus',
            'slug' => 'anomaly-corpus-'.bin2hex(random_bytes(4)),
            'kind' => 'bank',
            'iban' => 'NL00ASNB'.substr(bin2hex(random_bytes(8)), 0, 10),
            'default_currency' => 'EUR',
            'created_at' => '2026-06-13 00:00:00',
            'updated_at' => '2026-06-13 00:00:00',
        ]);
        $runId = $connection->table('import_runs')->insertGetId([
            'user_id' => $user->id,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/anomaly-corpus.csv',
            'sha256' => hash('sha256', 'corpus'.bin2hex(random_bytes(8))),
            'uploaded_at' => '2026-06-13 00:00:00',
            'status' => 'previewed',
            'created_at' => '2026-06-13 00:00:00',
            'updated_at' => '2026-06-13 00:00:00',
        ]);

        $counterpartyIds = [];
        $categoryIds = [];

        $resolveCounterparty = function (string $slug) use ($connection, $user, &$counterpartyIds): int {
            if (! isset($counterpartyIds[$slug])) {
                $counterpartyIds[$slug] = $connection->table('counterparties')->insertGetId([
                    'user_id' => $user->id,
                    'type' => 'merchant',
                    'slug' => $slug,
                    'display_name' => ucfirst(str_replace('-', ' ', $slug)),
                    'created_at' => '2026-06-13 00:00:00',
                    'updated_at' => '2026-06-13 00:00:00',
                ]);
            }

            return $counterpartyIds[$slug];
        };

        $resolveCategory = function (?string $slug) use ($connection, $user, &$categoryIds): ?int {
            if ($slug === null || $slug === '') {
                return null;
            }
            if (! isset($categoryIds[$slug])) {
                $categoryIds[$slug] = $connection->table('categories')->insertGetId([
                    'user_id' => $user->id,
                    'slug' => $slug,
                    'name' => ucfirst(str_replace('-', ' ', $slug)),
                    'kind' => 'expense',
                    'created_at' => '2026-06-13 00:00:00',
                    'updated_at' => '2026-06-13 00:00:00',
                ]);
            }

            return $categoryIds[$slug];
        };

        $rowIndex = 0;
        $insertTransaction = function (array $row, bool $onRecurringSeries) use (
            $connection,
            $user,
            $accountId,
            $runId,
            $resolveCounterparty,
            $resolveCategory,
            &$rowIndex
        ): array {
            $rowIndex++;
            $slug = is_string($row['counterparty'] ?? null) ? $row['counterparty'] : 'unknown';
            $counterpartyId = $resolveCounterparty($slug);
            $categoryId = $resolveCategory(is_string($row['category'] ?? null) ? $row['category'] : null);

            $amountMinor = (int) ($row['amount_minor'] ?? 0);
            $currency = is_string($row['currency'] ?? null) ? $row['currency'] : 'EUR';
            // Settled defaults to native amount/currency unless the fixture
            // overrides it (mixed-currency fixture exercises the divergence).
            $settledAmountMinor = (int) ($row['settled_amount_minor'] ?? $amountMinor);
            $settledCurrency = is_string($row['settled_currency'] ?? null) ? $row['settled_currency'] : $currency;
            $direction = is_string($row['direction'] ?? null) ? $row['direction'] : 'expense';
            $type = $direction === 'income' ? 'income' : 'expense';
            $postedAt = is_string($row['posted_at'] ?? null) ? $row['posted_at'] : '2026-06-15';

            $txnId = $connection->table('transactions')->insertGetId([
                'user_id' => $user->id,
                'account_id' => $accountId,
                'import_run_id' => $runId,
                'fingerprint' => hash('sha256', 'corpus-fp-'.$rowIndex.bin2hex(random_bytes(8))),
                'posted_at' => $postedAt,
                'booked_at' => $postedAt.' 00:00:00',
                'value_date' => $postedAt,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'settled_amount_minor' => $settledAmountMinor,
                'settled_currency' => $settledCurrency,
                'counterparty_id' => $counterpartyId,
                'category_id' => $categoryId,
                'counterparty_normalized' => $slug,
                'counterparty_name' => strtoupper($slug),
                'normalization_version' => 1,
                'description' => 'anomaly corpus '.$slug,
                'type' => $type,
                'source_format' => 'asn-csv',
                'source_row_index' => $rowIndex,
                'fingerprint_version' => 3,
                'created_at' => '2026-06-13 00:00:00',
                'updated_at' => '2026-06-13 00:00:00',
            ]);

            return ['id' => $txnId, 'counterparty_id' => $counterpartyId, 'on_recurring_series' => $onRecurringSeries];
        };

        // A lazily-created recurring series so occurrence rows can attach.
        $seriesId = null;
        $ensureSeries = function (int $counterpartyId) use ($connection, $user, &$seriesId): int {
            if ($seriesId === null) {
                $seriesId = $connection->table('recurring_series')->insertGetId([
                    'user_id' => $user->id,
                    'direction' => 'expense',
                    'detected_name' => 'corpus-series',
                    'state' => 'approved',
                    'cadence' => 'monthly',
                    'latest_amount_minor' => -1299,
                    'latest_currency' => 'EUR',
                    'variance_tolerance_percent' => 25,
                    'cluster_key' => 'corpus|series|'.$counterpartyId,
                    'created_at' => '2026-06-13 00:00:00',
                    'updated_at' => '2026-06-13 00:00:00',
                ]);
            }

            return $seriesId;
        };

        $attachOccurrence = function (array $txn, string $postedAt, int $amountMinor, string $currency) use ($connection, $user, $ensureSeries): void {
            $sid = $ensureSeries((int) $txn['counterparty_id']);
            $connection->table('recurring_series_occurrences')->insert([
                'user_id' => $user->id,
                'recurring_series_id' => $sid,
                'transaction_id' => (int) $txn['id'],
                'observed_at' => $postedAt,
                'observed_amount_minor' => $amountMinor,
                'observed_currency' => $currency,
                'created_at' => '2026-06-13 00:00:00',
                'updated_at' => '2026-06-13 00:00:00',
            ]);
        };

        // Seed history rows.
        /** @var list<array<string, mixed>> $history */
        $history = is_array($fixture['history'] ?? null) ? $fixture['history'] : [];
        foreach ($history as $row) {
            $onSeries = (bool) ($row['on_recurring_series'] ?? false);
            $txn = $insertTransaction($row, $onSeries);
            if ($onSeries) {
                $attachOccurrence(
                    $txn,
                    is_string($row['posted_at'] ?? null) ? $row['posted_at'] : '2026-06-12',
                    (int) ($row['settled_amount_minor'] ?? $row['amount_minor'] ?? 0),
                    is_string($row['settled_currency'] ?? null) ? $row['settled_currency'] : (is_string($row['currency'] ?? null) ? $row['currency'] : 'EUR'),
                );
            }
        }

        // Seed the transaction under test.
        /** @var array<string, mixed> $under */
        $under = is_array($fixture['transaction'] ?? null) ? $fixture['transaction'] : [];
        $underOnSeries = (bool) ($under['on_recurring_series'] ?? false);
        $underTxn = $insertTransaction($under, $underOnSeries);
        if ($underOnSeries) {
            $attachOccurrence(
                $underTxn,
                is_string($under['posted_at'] ?? null) ? $under['posted_at'] : '2026-06-15',
                (int) ($under['settled_amount_minor'] ?? $under['amount_minor'] ?? 0),
                is_string($under['settled_currency'] ?? null) ? $under['settled_currency'] : (is_string($under['currency'] ?? null) ? $under['currency'] : 'EUR'),
            );
        }

        // Seed suppression rules (counterparty-keyed) if present.
        /** @var list<array<string, mixed>> $rules */
        $rules = is_array($fixture['suppression_rules'] ?? null) ? $fixture['suppression_rules'] : [];
        foreach ($rules as $rule) {
            $slug = is_string($rule['counterparty'] ?? null) ? $rule['counterparty'] : null;
            $counterpartyId = $slug !== null ? $resolveCounterparty($slug) : null;
            $connection->table('anomaly_suppression_rules')->insert([
                'user_id' => $user->id,
                'counterparty_id' => $counterpartyId,
                'detector' => is_string($rule['detector'] ?? null) ? $rule['detector'] : 'large',
                'direction' => is_string($rule['direction'] ?? null) ? $rule['direction'] : 'expense',
                'amount_band_low_minor' => (int) ($rule['amount_band_low_minor'] ?? 0),
                'amount_band_high_minor' => (int) ($rule['amount_band_high_minor'] ?? 0),
                'currency' => is_string($rule['currency'] ?? null) ? $rule['currency'] : 'EUR',
                'created_at' => '2026-06-13 00:00:00',
                'updated_at' => '2026-06-13 00:00:00',
            ]);
        }

        return (int) $underTxn['id'];
    }

    /**
     * Loads a named corpus fixture array.
     *
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        /** @var array<string, mixed> $fixture */
        $fixture = require __DIR__.'/../fixtures/anomaly-corpus/'.$name.'.php';

        return $fixture;
    }

    /**
     * Creates a fresh anomaly test user. Shared across every evaluator /
     * detector test so the user shape (username, currency view) stays
     * consistent.
     */
    public static function makeUser(): User
    {
        return User::query()->create([
            'username' => 'anom-'.bin2hex(random_bytes(4)),
            'password' => 'fixture-password',
            'period_start_day' => 1,
            'default_currency_view' => 'eur_only',
        ]);
    }

    /**
     * Loads a seeded transaction row as the raw associative array the
     * detectors and the evaluator consume.
     *
     * @return array<string, mixed>
     */
    public static function transactionRow(DatabaseManager $db, int $txnId): array
    {
        /** @var \stdClass $row */
        $row = $db->connection()->table('transactions')->where('id', $txnId)->first();

        return (array) $row;
    }
}
