<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

// One open drift alert with the whole chain behind it. latest_occurrence_id is
// a NOT NULL FK, so an alert cannot be conjured from the factory alone: it
// needs an account, an import run, a transaction and an occurrence first.
final class DriftAlertFixture
{
    public static function user(string $prefix): User
    {
        return User::query()->create([
            'username' => $prefix.'-'.bin2hex(random_bytes(5)),
            'password' => 'fixture',
            'period_start_day' => 1,
            'default_currency_view' => 'eur_only',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides  applied to the drift_alerts row
     */
    public static function alert(User $user, array $overrides = [], string $currency = Currency::Eur->value): DriftAlert
    {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $connection = $db->connection();
        $suffix = bin2hex(random_bytes(4));

        $seriesId = $connection->table('recurring_series')->insertGetId([
            'user_id' => $user->id,
            'direction' => 'expense',
            'detected_name' => 'Merchant '.$suffix,
            'state' => RecurringSeriesState::Approved->value,
            'cadence' => SeriesCadence::Monthly->value,
            'latest_amount_minor' => -1149,
            'latest_currency' => $currency,
            'variance_tolerance_percent' => 25,
            'cluster_key' => 'fixture::'.$suffix,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);

        $accountId = $connection->table('accounts')->insertGetId([
            'user_id' => $user->id,
            'name' => 'ASN test',
            'slug' => 'fixture-'.$suffix,
            'kind' => 'bank',
            'iban' => 'NL00FIXT'.mb_strtoupper($suffix),
            'default_currency' => Currency::Eur->value,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
        $runId = $connection->table('import_runs')->insertGetId([
            'user_id' => $user->id,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/fixture-'.$suffix.'.csv',
            'sha256' => hash('sha256', 'fixture-run-'.$suffix),
            'uploaded_at' => '2026-05-19 00:00:00',
            'status' => 'previewed',
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
        $txId = $connection->table('transactions')->insertGetId([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'fixture-'.$suffix),
            'posted_at' => '2026-05-15',
            'booked_at' => '2026-05-15 00:00:00',
            'value_date' => '2026-05-15',
            'amount_minor' => -1149,
            'currency' => $currency,
            'settled_amount_minor' => -1149,
            'settled_currency' => $currency,
            'counterparty_normalized' => 'merchant-'.$suffix,
            'counterparty_name' => 'MERCHANT',
            'normalization_version' => 1,
            'description' => 'drift fixture',
            'type' => 'expense',
            'source_format' => 'asn-csv',
            'source_row_index' => 1,
            'fingerprint_version' => 3,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
        $occurrenceId = $connection->table('recurring_series_occurrences')->insertGetId([
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'transaction_id' => $txId,
            'observed_at' => '2026-05-15',
            'observed_amount_minor' => -1149,
            'observed_currency' => $currency,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);

        return DriftAlert::factory()->create(array_merge([
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'state' => DriftAlertState::Open->value,
            'currency' => $currency,
            'latest_occurrence_id' => $occurrenceId,
            'detected_at' => CarbonImmutable::parse('2026-05-19 12:00:00'),
            'actioned_at' => null,
            'snoozed_until' => null,
        ], $overrides));
    }
}
