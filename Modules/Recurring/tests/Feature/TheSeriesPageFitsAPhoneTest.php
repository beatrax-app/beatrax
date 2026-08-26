<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

// Measured on an iPhone 12 mini at /recurring/series/1: the header's control
// group was 400px wide and shrink-0, so on a 375pt screen it took the title
// column down to a sliver — "View Woonstichting Delta profile" wrapped one word
// per line and the amount's minus sign broke onto a line of its own — and its
// last control sat 59px past the right edge. The chart under it declared no y
// axis, so ApexCharts drew -1449.5 / -1450 / -1450.5 where the same amount two
// centimetres above read -€1,450.00.

function sfpSeededSeries(DatabaseManager $db, User $user): RecurringSeries
{
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'sfp account',
        'slug' => 'sfp-account',
        'kind' => 'bank',
        'iban' => 'NL00SFP0000000001',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sfp.csv',
        'sha256' => str_repeat('f', 64),
        'uploaded_at' => CarbonImmutable::parse('2026-05-17 00:00:00'),
        'status' => 'previewed',
    ]);

    $series = RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'woonstichting delta',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -145000,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -145000,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::woonstichting delta::eur::monthly',
        'next_expected_at' => '2026-06-01',
        'next_expected_confidence_low' => false,
    ]);

    for ($i = 0; $i < 4; $i++) {
        $date = sprintf('2026-%02d-01', $i + 2);
        $transactionId = (int) $db->connection()->table('transactions')->insertGetId([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'posted_at' => $date,
            'booked_at' => $date.' 12:00:00',
            'value_date' => $date,
            'amount_minor' => -145000,
            'currency' => 'EUR',
            'settled_amount_minor' => -145000,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'woonstichting delta',
            'counterparty_normalized' => 'woonstichting delta',
            'normalization_version' => 3,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $i + 1,
            'fingerprint' => str_pad('sfp-'.$i, 64, 'z', STR_PAD_LEFT),
            'fingerprint_version' => 3,
            'created_at' => '2026-05-17 12:00:00',
            'updated_at' => '2026-05-17 12:00:00',
        ]);

        RecurringSeriesOccurrence::query()->create([
            'user_id' => $user->id,
            'recurring_series_id' => $series->id,
            'transaction_id' => $transactionId,
            'observed_at' => $date,
            'observed_amount_minor' => -145000,
            'observed_currency' => 'EUR',
        ]);
    }

    return $series;
}

it('lets the controls beside the title wrap once the screen is narrower than they are', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php')
    );

    expect($blade)->toContain('sm:shrink-0')
        ->and($blade)->not->toContain('flex shrink-0 flex-wrap')
        ->and($blade)->toContain('flex flex-col gap-4 sm:flex-row');
});

it('declares the y axis that carries the money formatter', function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $user = User::query()->create([
        'username' => 'sfp-reader',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $series = sfpSeededSeries($db, $user);

    $content = (string) $this->actingAs($user)
        ->get(route('recurring.series.show', ['seriesId' => $series->id]))
        ->assertOk()
        ->getContent();

    expect(preg_match('/data-options=("|\')([^"\']*)\1/u', $content, $matches))->toBe(1);

    /** @var array<string, mixed> $parsed */
    $parsed = json_decode(html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

    expect($parsed['yaxis'] ?? null)->toBeArray();

    CarbonImmutable::setTestNow();
});

it('formats an axis the chart did not declare, rather than leaving it raw', function (): void {
    $js = (string) file_get_contents(base_path('resources/js/app.js'));

    expect($js)->toContain('const withFormatter = (axis) => {')
        ->and($js)->not->toContain('if (!axis || Array.isArray(axis)) return axis;');
});
