<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// The ASN-direct and fuzzy arms both exclude a bank row another non-rejected
// funding link already cites. The deterministic arm delegates its candidate
// search and carried no such exclusion, so two PayPal withdrawals of one amount
// in one window both named the same bank deposit -- confirmed, at 1.000, so
// neither ever reached the review queue -- while a second deposit of the same
// size went unlinked.

uses(RefreshDatabase::class);

/**
 * @return array{user: User, paypal: Account, asn: Account, run: ImportRun}
 */
function oneClaimFixture(): array
{
    $user = User::query()->create([
        'username' => 'one-claim-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    return [
        'user' => $user,
        'paypal' => Account::query()->create([
            'user_id' => $user->id, 'name' => 'PayPal', 'slug' => 'oc-pp',
            'kind' => 'paypal', 'iban' => 'PAYPALOC', 'default_currency' => 'EUR',
        ]),
        'asn' => Account::query()->create([
            'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'oc-asn',
            'kind' => 'bank', 'iban' => 'NL57ASNB0123456789', 'default_currency' => 'EUR',
        ]),
        'run' => ImportRun::query()->create([
            'user_id' => $user->id, 'source_format' => 'paypal-csv',
            'raw_file_path' => '/tmp/one-claim.csv', 'sha256' => str_repeat('o', 64),
            'uploaded_at' => CarbonImmutable::now(), 'status' => 'previewed',
        ]),
    ];
}

/**
 * @param  array{user: User, paypal: Account, asn: Account, run: ImportRun}  $fixture
 * @param  array<string, mixed>  $overrides
 */
function oneClaimTx(array $fixture, Account $account, array $overrides): Transaction
{
    static $row = 0;
    $row++;

    return Transaction::query()->create(array_merge([
        'user_id' => $fixture['user']->id,
        'account_id' => $account->id,
        'type' => 'transfer_in',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => 5000,
        'currency' => 'EUR',
        'settled_amount_minor' => 5000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'PayPal',
        'counterparty_normalized' => 'paypal',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $fixture['run']->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad('ocl'.$row, 64, 'o', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

/**
 * @param  array{user: User, paypal: Account, asn: Account, run: ImportRun}  $fixture
 */
function oneClaimWithdrawal(array $fixture, string $key, string $day): Transaction
{
    return oneClaimTx($fixture, $fixture['paypal'], [
        'type' => 'transfer_out',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'counterparty_normalized' => $key,
        'source_format' => 'paypal-csv',
        'posted_at' => $day,
        'booked_at' => $day.' 12:00:00',
        'value_date' => $day,
        'raw_payload' => [
            'format' => 'paypal-csv',
            'language' => 'nl',
            'events' => [['type' => 'Bankstorting', 'row' => ['Naam' => 'Withdraw to bank NL57ASNB0123456789']]],
        ],
    ]);
}

it('leaves one bank deposit claimed by one withdrawal, not by both', function (): void {
    $fixture = oneClaimFixture();
    oneClaimWithdrawal($fixture, 'pp-w1', '2026-05-15');
    oneClaimWithdrawal($fixture, 'pp-w2', '2026-05-16');

    $deposit = oneClaimTx($fixture, $fixture['asn'], ['counterparty_normalized' => 'dep-1']);

    app(PaypalFundingResolver::class)->resolveForUser($fixture['user']);

    $claims = ChainLink::query()
        ->where('user_id', $fixture['user']->id)
        ->where('to_transaction_id', $deposit->id)
        ->count();

    expect($claims)->toBe(1);
});

it('gives the second withdrawal the deposit the first one left', function (): void {
    $fixture = oneClaimFixture();
    oneClaimWithdrawal($fixture, 'pp-w1', '2026-05-15');
    oneClaimWithdrawal($fixture, 'pp-w2', '2026-05-15');

    oneClaimTx($fixture, $fixture['asn'], ['counterparty_normalized' => 'dep-1']);
    oneClaimTx($fixture, $fixture['asn'], [
        'counterparty_normalized' => 'dep-2',
        'posted_at' => '2026-05-16',
        'booked_at' => '2026-05-16 12:00:00',
        'value_date' => '2026-05-16',
    ]);

    app(PaypalFundingResolver::class)->resolveForUser($fixture['user']);

    $claimed = ChainLink::query()
        ->where('user_id', $fixture['user']->id)
        ->pluck('to_transaction_id')
        ->all();

    expect(count(array_unique($claimed)))->toBe(count($claimed));
});
