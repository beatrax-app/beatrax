<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Internal\Http\Livewire\RecurringSeriesDetailPage;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

// The amount trend divided every point by a hundred whatever the series was
// quoted in, so a ¥9,800 subscription plotted at 98 against a table still
// printing the true figure.

const YEN_SERIES_AMOUNT_MINOR = -9_800;

function yenSeriesFixture(DatabaseManager $db, string $currency, int $amountMinor): array
{
    $user = User::query()->create([
        'username' => 'yen-series-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => $currency,
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Tokyo',
        'slug' => 'yen-series-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'JP00YSC'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => $currency,
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/yen-series.csv',
        'sha256' => hash('sha256', 'yen-series-'.$user->id),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    $series = RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'yen-sub',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => $amountMinor,
        'latest_currency' => $currency,
        'monthly_equivalent_minor' => $amountMinor,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::yen-sub::'.strtolower($currency).'::monthly::'.$user->id,
        'next_expected_at' => '2026-06-17',
        'next_expected_confidence_low' => false,
    ]);

    foreach (['2026-03-15', '2026-04-15'] as $index => $date) {
        $txId = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'posted_at' => $date,
            'booked_at' => $date.' 12:00:00',
            'value_date' => $date,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'settled_amount_minor' => $amountMinor,
            'settled_currency' => $currency,
            'counterparty_name' => 'yen-sub',
            'counterparty_normalized' => 'yen-sub',
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $index + 1,
            'fingerprint' => hash('sha256', 'yen-series-'.$user->id.'-'.$index),
            'fingerprint_version' => 3,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);

        RecurringSeriesOccurrence::query()->create([
            'user_id' => $user->id,
            'recurring_series_id' => $series->id,
            'transaction_id' => $txId,
            'observed_at' => $date,
            'observed_amount_minor' => $amountMinor,
            'observed_currency' => $currency,
        ]);
    }

    return ['user' => $user, 'series' => $series];
}

/**
 * @return list<float>
 */
function yenSeriesChartValues(User $user, int $seriesId): array
{
    $options = Livewire::actingAs($user)
        ->test(RecurringSeriesDetailPage::class, ['seriesId' => $seriesId])
        ->viewData('apexOptions');

    return array_map(
        static fn (array $point): float => (float) $point['y'],
        is_array($options) ? $options['series'][0]['data'] : [],
    );
}

it('plots a yen series at whole yen, not at a hundredth of one', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $fixture = yenSeriesFixture($db, 'JPY', YEN_SERIES_AMOUNT_MINOR);

    expect(yenSeriesChartValues($fixture['user'], $fixture['series']->id))
        ->toBe([(float) YEN_SERIES_AMOUNT_MINOR, (float) YEN_SERIES_AMOUNT_MINOR]);
});

it('still plots a two-decimal series in major units', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $fixture = yenSeriesFixture($db, 'EUR', YEN_SERIES_AMOUNT_MINOR);

    expect(yenSeriesChartValues($fixture['user'], $fixture['series']->id))
        ->toBe([-98.0, -98.0]);
});
