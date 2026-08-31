<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Models\ChainLink;
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

function vbftClock(): Clock
{
    return new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-05-01 00:00:00');
        }
    };
}

function vbftUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function vbftAccount(User $user, string $kind, string $slug): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'vbft '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => 'VBFT-'.strtoupper($slug),
        'default_currency' => 'EUR',
    ]);
}

beforeEach(function (): void {
    $this->user = vbftUser('vbft');
    $this->router = app(ChainAwareForecastRouter::class);
});

it('returns per-series contributions unchanged when viewByFunder is false', function (): void {
    $asn = vbftAccount($this->user, 'bank', 'asn');

    $a = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -1000,
        lowMinor: -1100,
        highMinor: -900,
        currency: 'EUR',
        seriesId: 101,
        accountId: $asn->id,
    );
    $b = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -2000,
        lowMinor: -2200,
        highMinor: -1800,
        currency: 'EUR',
        seriesId: 202,
        accountId: $asn->id,
    );

    $routed = $this->router->route([$a, $b], $this->user);

    expect($routed)->toHaveCount(2);
    expect($routed[0]->seriesId)->toBe(101);
    expect($routed[1]->seriesId)->toBe(202);
});

it('aggregates per-day per-account contributions when viewByFunder is true', function (): void {
    $asn = vbftAccount($this->user, 'bank', 'asn');

    $a = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -1000,
        lowMinor: -1100,
        highMinor: -900,
        currency: 'EUR',
        seriesId: 101,
        accountId: $asn->id,
    );
    $b = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -2000,
        lowMinor: -2200,
        highMinor: -1800,
        currency: 'EUR',
        seriesId: 202,
        accountId: $asn->id,
    );

    $routed = $this->router->collapseByFunder($this->router->route([$a, $b], $this->user));

    // Single (accountId, date) bucket — point/low/high are signed sums.
    expect($routed)->toHaveCount(1);
    expect($routed[0]->pointMinor)->toBe(-3000);
    expect($routed[0]->lowMinor)->toBe(-3300);
    expect($routed[0]->highMinor)->toBe(-2700);
    expect($routed[0]->seriesId)->toBe(0); // Aggregate marker.
    expect($routed[0]->accountId)->toBe($asn->id);
});

it('keeps contributions on distinct days as separate entries even when viewByFunder is true', function (): void {
    $asn = vbftAccount($this->user, 'bank', 'asn');

    $a = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -1000,
        lowMinor: -1100,
        highMinor: -900,
        currency: 'EUR',
        seriesId: 101,
        accountId: $asn->id,
    );
    $b = new ForecastContribution(
        date: CarbonImmutable::parse('2026-06-15'),
        pointMinor: -2000,
        lowMinor: -2200,
        highMinor: -1800,
        currency: 'EUR',
        seriesId: 101,
        accountId: $asn->id,
    );

    $routed = $this->router->collapseByFunder($this->router->route([$a, $b], $this->user));

    expect($routed)->toHaveCount(2);
});

it('collapses chain-resolved per-series contributions onto the funder ASN account when viewByFunder is true', function (): void {
    $asn = vbftAccount($this->user, 'bank', 'asn');
    $paypal = vbftAccount($this->user, 'paypal', 'paypal');

    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/vbft.csv',
        'sha256' => str_repeat('v', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $row = 1;
    $rowFn = static function () use (&$row): int {
        return $row++;
    };

    $funded = Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $paypal->id,
        'type' => 'expense',
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 09:00:00',
        'value_date' => '2026-04-15',
        'amount_minor' => -12000,
        'currency' => 'EUR',
        'settled_amount_minor' => -12000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Netflix',
        'counterparty_normalized' => 'netflix',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowFn(),
        'fingerprint' => str_pad('vbft-funded', 64, 'f', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
    $funder = Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $asn->id,
        'type' => 'expense',
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 09:00:00',
        'value_date' => '2026-04-15',
        'amount_minor' => -12000,
        'currency' => 'EUR',
        'settled_amount_minor' => -12000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'PayPal ASN',
        'counterparty_normalized' => 'paypal asn',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowFn(),
        'fingerprint' => str_pad('vbft-funder', 64, 'g', STR_PAD_LEFT),
        'fingerprint_version' => 3,
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
        'cluster_key' => 'vbft-netflix',
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

    $contribA = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -12000,
        lowMinor: -12600,
        highMinor: -11400,
        currency: 'EUR',
        seriesId: $series->id,
        accountId: $paypal->id, // Pre-routing, on the original account.
    );

    $routed = $this->router->collapseByFunder($this->router->route([$contribA], $this->user));

    // The collapse runs after the chain rewrite, so the entry it produces is
    // already on the ASN funder rather than on PayPal.
    expect($routed)->toHaveCount(1);
    expect($routed[0]->accountId)->toBe($asn->id);
    expect($routed[0]->seriesId)->toBe(0);
    expect($routed[0]->pointMinor)->toBe(-12000);
});

it('keeps two currencies on one account and day apart instead of summing them under the first one', function (): void {
    $asn = vbftAccount($this->user, 'bank', 'asn');

    $euro = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -1000,
        lowMinor: -1100,
        highMinor: -900,
        currency: 'EUR',
        seriesId: 101,
        accountId: $asn->id,
    );
    $dollar = new ForecastContribution(
        date: CarbonImmutable::parse('2026-05-15'),
        pointMinor: -2000,
        lowMinor: -2200,
        highMinor: -1800,
        currency: 'USD',
        seriesId: 202,
        accountId: $asn->id,
    );

    $collapsed = $this->router->collapseByFunder([$euro, $dollar]);

    // -3000 under 'EUR' is a total in a currency the money was never in: the
    // dollars have not been through a rate yet, and only the fold applies one.
    expect($collapsed)->toHaveCount(2);

    $byCurrency = [];
    foreach ($collapsed as $c) {
        $byCurrency[$c->currency] = $c;
    }
    expect(array_keys($byCurrency))->toEqualCanonicalizing(['EUR', 'USD']);
    expect($byCurrency['EUR']->pointMinor)->toBe(-1000);
    expect($byCurrency['USD']->pointMinor)->toBe(-2000);
});
