<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Pipeline\ChainAwareForecastRouter;
use Modules\Forecasting\Internal\Pipeline\ForecastContribution;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

uses(RefreshDatabase::class);

function carfUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function carfAccount(User $user, string $kind, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'carf '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => 'CARF-'.strtoupper($slug),
        'default_currency' => 'EUR',
    ]);
}

function carfImportRun(User $user, string $sha): ImportRun
{
    return ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/carf-'.substr($sha, 0, 6).'.csv',
        'sha256' => $sha,
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function carfTxn(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
{
    static $row = 0;
    $row++;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 09:00:00',
        'value_date' => '2026-04-15',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Carf Co',
        'counterparty_normalized' => 'carf co',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad('carf-'.$row, 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ];

    return Transaction::query()->create(array_merge($defaults, $overrides));
}

function carfRouter(?Clock $clock = null): ChainAwareForecastRouter
{
    $clock ??= new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-05-01 00:00:00');
        }
    };

    /** @var ChainLinkQuery $chainQuery */
    $chainQuery = app(ChainLinkQuery::class);
    /** @var CardStatementQuery $cardStatementQuery */
    $cardStatementQuery = app(CardStatementQuery::class);

    return new ChainAwareForecastRouter($chainQuery, $cardStatementQuery, $clock);
}

beforeEach(function (): void {
    $this->user = carfUser('router');
});

it('passes contributions through unchanged when no chain links + no next settlement exist', function (): void {
    $asn = carfAccount($this->user, 'bank', 'asn');
    $contribution = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-10'),
        pointMinor: -12000,
        lowMinor: -12600,
        highMinor: -11400,
        currency: 'EUR',
        seriesId: 101,
        accountId: $asn->id,
    );

    $routed = carfRouter()->route([$contribution], $this->user);

    expect($routed)->toHaveCount(1);
    expect($routed[0]->accountId)->toBe($asn->id);
    expect($routed[0]->seriesId)->toBe(101);
});

it('rewrites a PayPal series contribution onto the ASN funder via confirmed chain link', function (): void {
    $asn = carfAccount($this->user, 'bank', 'asn');
    $paypal = carfAccount($this->user, 'paypal', 'paypal');
    $run = carfImportRun($this->user, str_repeat('a', 64));

    $funded = carfTxn($this->user, $paypal, $run, [
        'amount_minor' => -12000,
        'counterparty_name' => 'Netflix',
        'counterparty_normalized' => 'netflix',
    ]);
    $funder = carfTxn($this->user, $asn, $run, [
        'amount_minor' => -12000,
        'counterparty_name' => 'PayPal ASN',
        'counterparty_normalized' => 'paypal asn',
    ]);

    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'cadence' => 'monthly',
        'direction' => 'expense',
        'detected_name' => 'Netflix',
        'state' => 'approved',
        'variance_tolerance_percent' => 5,
        'latest_amount_minor' => -12000,
        'latest_currency' => 'EUR',
        'cluster_key' => 'netflix',
        'next_expected_at' => '2026-05-15',
    ]);
    RecurringSeriesOccurrence::query()->create([
        'user_id' => $this->user->id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $funded->id,
        'observed_at' => '2026-04-15',
        'observed_amount_minor' => -12000,
        'observed_currency' => 'EUR',
    ]);

    ChainLink::query()->create([
        'user_id' => $this->user->id,
        'from_transaction_id' => $funded->id,
        'to_transaction_id' => $funder->id,
        'kind' => 'paypal_funding',
        'state' => 'confirmed',
        'confidence' => 1.0,
        // 'auto' is what the resolvers write and the only value
        // ChainLinkQuery::confirmedFundersForSeries routes on; nothing
        // in the app ever writes 'user'.
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'paypal-netflix']),
    ]);

    $contribution = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -12000,
        lowMinor: -12600,
        highMinor: -11400,
        currency: 'EUR',
        seriesId: $series->id,
        accountId: $paypal->id,
    );

    $routed = carfRouter()->route([$contribution], $this->user);

    expect($routed)->toHaveCount(1);
    expect($routed[0]->accountId)->toBe($asn->id);
    expect($routed[0]->pointMinor)->toBe(-12000);
});

it('leaves a PayPal series contribution unchanged when there is no chain link', function (): void {
    $paypal = carfAccount($this->user, 'paypal', 'paypal');

    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'cadence' => 'monthly',
        'direction' => 'expense',
        'detected_name' => 'Loose PayPal',
        'state' => 'approved',
        'variance_tolerance_percent' => 5,
        'latest_amount_minor' => -3000,
        'latest_currency' => 'EUR',
        'cluster_key' => 'loose-paypal',
        'next_expected_at' => '2026-05-15',
    ]);

    $contribution = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -3000,
        lowMinor: -3150,
        highMinor: -2850,
        currency: 'EUR',
        seriesId: $series->id,
        accountId: $paypal->id,
    );

    $routed = carfRouter()->route([$contribution], $this->user);

    expect($routed)->toHaveCount(1);
    expect($routed[0]->accountId)->toBe($paypal->id);
});

it('synthesises a next ICS bulk-iDEAL settlement contribution onto the funder ASN account', function (): void {
    $asn = carfAccount($this->user, 'bank', 'asn');
    $ics = carfAccount($this->user, 'ics_card', 'ics');
    $run = carfImportRun($this->user, str_repeat('b', 64));

    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -120000,
        'open_balance_minor' => 120000,
        'state' => 'open',
    ]);

    $routed = carfRouter()->route([], $this->user);

    expect($routed)->toHaveCount(1);
    expect($routed[0]->accountId)->toBe($asn->id);
    expect($routed[0]->pointMinor)->toBe(-120000);
    expect($routed[0]->currency)->toBe('EUR');
    expect($routed[0]->date->toDateString())->toBe('2026-05-05'); // period_end + 5 days
});

it('de-duplicates a synthesised settlement against a chain-routed ICS series contribution on the same (funder, date)', function (): void {
    $asn = carfAccount($this->user, 'bank', 'asn');
    $ics = carfAccount($this->user, 'ics_card', 'ics');
    $run = carfImportRun($this->user, str_repeat('c', 64));

    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -50000,
        'open_balance_minor' => 50000,
        'state' => 'open',
    ]);

    // The chain link is what puts this series on the ASN funder, the same
    // account and date the synthesised settlement lands on — which is the
    // collision the dedup has to resolve.
    $funded = carfTxn($this->user, $ics, $run, [
        'amount_minor' => -50000,
        'counterparty_name' => 'ICS Bulk',
        'counterparty_normalized' => 'ics bulk',
    ]);
    $funder = carfTxn($this->user, $asn, $run, [
        'amount_minor' => -50000,
        'counterparty_name' => 'ICS Settle',
        'counterparty_normalized' => 'ics settle',
    ]);
    $series = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'cadence' => 'monthly',
        'direction' => 'expense',
        'detected_name' => 'ICS Bulk',
        'state' => 'approved',
        'variance_tolerance_percent' => 5,
        'latest_amount_minor' => -50000,
        'latest_currency' => 'EUR',
        'cluster_key' => 'ics-bulk',
        'next_expected_at' => '2026-05-05',
    ]);
    RecurringSeriesOccurrence::query()->create([
        'user_id' => $this->user->id,
        'recurring_series_id' => $series->id,
        'transaction_id' => $funded->id,
        'observed_at' => '2026-04-05',
        'observed_amount_minor' => -50000,
        'observed_currency' => 'EUR',
    ]);
    ChainLink::query()->create([
        'user_id' => $this->user->id,
        'from_transaction_id' => $funded->id,
        'to_transaction_id' => $funder->id,
        'kind' => 'ics_bulk_settle',
        'state' => 'confirmed',
        'confidence' => 1.0,
        // 'auto' is what the resolvers write and the only value
        // ChainLinkQuery::confirmedFundersForSeries routes on; nothing
        // in the app ever writes 'user'.
        'resolver' => 'auto',
        'evidence' => json_encode(['signature_hash' => 'ics-bulk']),
    ]);

    $overlap = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-05'),
        pointMinor: -50000,
        lowMinor: -55000,
        highMinor: -45000,
        currency: 'EUR',
        seriesId: $series->id,
        accountId: $ics->id,
    );

    $routed = carfRouter()->route([$overlap], $this->user);

    expect($routed)->toHaveCount(1);
    expect($routed[0]->seriesId)->toBe(0);
    expect($routed[0]->pointMinor)->toBe(-50000);
    expect($routed[0]->lowMinor)->toBe(-50000);
    expect($routed[0]->highMinor)->toBe(-50000);
});

it('preserves an unrelated ASN recurring series whose occurrence lands on the ICS settlement date', function (): void {
    $asn = carfAccount($this->user, 'bank', 'asn');
    $ics = carfAccount($this->user, 'ics_card', 'ics');
    $run = carfImportRun($this->user, str_repeat('d', 64));

    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $ics->id,
        'import_run_id' => $run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -50000,
        'open_balance_minor' => 50000,
        'state' => 'open',
    ]);

    // Unrelated to the card, but lands on 2026-05-05 like the synthesised
    // settlement does. The earlier dedup keyed on (account, date) alone and
    // dropped this inflow wholesale.
    $salarySeries = RecurringSeries::query()->create([
        'user_id' => $this->user->id,
        'cadence' => 'monthly',
        'direction' => 'income',
        'detected_name' => 'Salary',
        'state' => 'approved',
        'variance_tolerance_percent' => 5,
        'latest_amount_minor' => 250000,
        'latest_currency' => 'EUR',
        'cluster_key' => 'salary',
        'next_expected_at' => '2026-05-05',
    ]);

    $salaryInflow = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-05'),
        pointMinor: 250000,
        lowMinor: 247500,
        highMinor: 252500,
        currency: 'EUR',
        seriesId: $salarySeries->id,
        accountId: $asn->id,
    );

    $routed = carfRouter()->route([$salaryInflow], $this->user);

    expect($routed)->toHaveCount(2);

    $bySeries = [];
    foreach ($routed as $c) {
        $bySeries[$c->seriesId] = $c;
    }
    expect($bySeries)->toHaveKey($salarySeries->id);
    expect($bySeries)->toHaveKey(0);
    expect($bySeries[$salarySeries->id]->pointMinor)->toBe(250000);
    expect($bySeries[0]->pointMinor)->toBe(-50000);
});
