<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Internal\Aggregation\ReportMetric;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;

uses(RefreshDatabase::class);

// Money that came in and money that went out are one column with a sign, and
// the report adds it once. Adding the two halves apart and subtracting them
// looks like the same arithmetic until a rate rounds each half on its own:
// the halves round outward, the whole rounds once, and the two answers are a
// cent apart. This suite builds a rate world where they are.
beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
});

function nssUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'nss-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function nssAccount(User $user, string $currency): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $currency.' account',
        'slug' => 'nss-'.strtolower($currency).'-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00NSS'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => $currency,
    ]);
}

// Two euros to the dollar, so a dollar cent is half a euro cent and every
// odd number of them lands exactly on the rounding boundary.
function nssRate(DatabaseManager $db, string $quote, string $rate): void
{
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => $quote,
        'rate_date' => '2026-04-01',
        'rate' => $rate,
        'source' => 'ecb',
        'created_at' => '2026-04-01 00:00:00',
        'updated_at' => '2026-04-01 00:00:00',
    ]);
}

function nssTransaction(DatabaseManager $db, User $user, Account $account, string $type, int $minor): void
{
    $suffix = bin2hex(random_bytes(8));

    $db->connection()->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $db->connection()->table('import_runs')->insertGetId([
            'user_id' => $user->id,
            'source_format' => 'asn-csv',
            'raw_file_path' => '/tmp/nss-'.$suffix.'.csv',
            'sha256' => hash('sha256', 'nss-run-'.$suffix),
            'uploaded_at' => '2026-04-10 00:00:00',
            'status' => 'committed',
            'created_at' => '2026-04-10 00:00:00',
            'updated_at' => '2026-04-10 00:00:00',
        ]),
        'type' => $type,
        'posted_at' => '2026-04-10',
        'booked_at' => '2026-04-10 10:00:00',
        'value_date' => '2026-04-10',
        'amount_minor' => $minor,
        'currency' => $account->default_currency,
        'settled_amount_minor' => $minor,
        'settled_currency' => $account->default_currency,
        'counterparty_name' => 'NSS Vendor',
        'counterparty_normalized' => 'nss-vendor',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'nss-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => '2026-04-10 00:00:00',
        'updated_at' => '2026-04-10 00:00:00',
    ]);
}

function nssDefinition(string $metric): ReportDefinition
{
    return new ReportDefinition(
        metric: $metric,
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-04-01',
        customTo: '2026-04-30',
    );
}

it('totals a period in one signed pass, not as its two halves rounded apart and subtracted', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = nssUser();
    nssRate($db, 'USD', '2.00000000');
    $account = nssAccount($user, 'USD');

    nssTransaction($db, $user, $account, 'income', 1);
    nssTransaction($db, $user, $account, 'expense', -2);

    $aggregator = app(ReportAggregator::class);

    $net = $aggregator->run($user, nssDefinition('net'));
    $income = $aggregator->run($user, nssDefinition('income'));
    $spend = $aggregator->run($user, nssDefinition('spend'));

    $subtracted = $income->totalMinor - $spend->totalMinor;

    // The fixture has to be one where the two routes actually part, or the
    // assertion below passes over an implementation this rule forbids.
    expect($subtracted)->not->toBe($net->totalMinor, implode("\n", [
        'The net total and income-minus-spend came to the same figure, '.$net->totalMinor.'.',
        '',
        'On this fixture they must differ: a dollar cent converted alone rounds up',
        'on each side and cancels, while the signed sum rounds once and does not.',
        'Reading the same number from both routes means net is now being derived',
        'by subtracting two separately-rounded halves.',
    ]));

    // USD 0.01 in against USD 0.02 out is USD -0.01, which is EUR -0.005 and
    // rounds away from zero to one euro cent out. Rounded apart it is one cent
    // in against one cent out, and the reader is told they broke even.
    expect($net->totalMinor)->toBe(-1);
    expect($net->currency)->toBe('EUR');
    expect($subtracted)->toBe(0);
});

it('asks the ledger for one sum of the signed column per metric', function (): void {
    $expressions = [];

    foreach (['', 't.', 'ts.'] as $prefix) {
        $expressions[$prefix] = ReportMetric::Net->sumExpr($prefix);
    }

    expect(count($expressions))->toBe(3);

    $offenders = [];

    foreach ($expressions as $prefix => $expression) {
        if (substr_count($expression, 'SUM(') !== 1 || $expression !== 'SUM('.$prefix.'settled_amount_minor)') {
            $offenders[] = ($prefix === '' ? '(no prefix)' : $prefix).' → '.$expression;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These ask the ledger for a net figure in more than one piece:',
        ...$offenders,
        '',
        'Net is the signed column summed once. Two sums subtracted — whether in',
        'SQL or by running the report twice — round separately, and the halves',
        'stop adding up to the whole the moment a rate is involved.',
    ]));
});
