<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Transfers\Public\Enums\CounterLegOrder;
use Modules\Transfers\Public\Services\PairLookup;
use Modules\Transfers\Public\Support\CounterLegMatch;
use Modules\Transfers\Public\Support\CounterLegWindow;

// The resolver used to carry a private findPartnerOnAccount(), so the
// counter-leg query existed twice and the copy Transfers owns could never be
// the one that ran. These two hold the seam shut: no second copy in the
// resolver, and the id it links is the id PairLookup returns for the same ask.

function counterLegParityAsnAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN counter-leg parity',
        'slug' => 'clp-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
}

function counterLegParityPaypalAccount(User $user): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal counter-leg parity',
        'slug' => 'clp-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
}

function counterLegParityTransferIn(
    User $user,
    Account $account,
    ImportRun $run,
    int $amountMinor,
    string $postedAt,
    string $fingerprintSeed,
): Transaction {
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => TransactionType::TransferIn->value,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'PayPal',
        'counterparty_normalized' => 'paypal',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 40 + strlen($fingerprintSeed),
        'fingerprint' => str_pad($fingerprintSeed, 64, 'c', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'counter-leg-parity',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->asn = counterLegParityAsnAccount($this->user);
    $this->paypal = counterLegParityPaypalAccount($this->user);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/counter-leg-parity.csv',
        'sha256' => str_repeat('c', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

it('keeps the counter-leg query out of PaypalFundingResolver and inside Transfers::PairLookup', function (): void {
    $source = (string) file_get_contents(base_path('Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php'));

    expect(str_contains($source, PairLookup::class))->toBeTrue(
        'PaypalFundingResolver no longer reaches Transfers for the transfer counter-leg. '
        .'A second copy of that query inside Chains is how the two drifted apart the first time.',
    );

    $ownPartnerLookups = PatternScan::all('/private function (\w*[Pp]artner\w*)\s*\(/', $source);

    expect($ownPartnerLookups[1])->toBe(
        [],
        'PaypalFundingResolver declares its own partner lookup again: '
        .implode(', ', $ownPartnerLookups[1])
        .'. The transfer counter-leg query belongs to Modules\Transfers\Public\Services\PairLookup.',
    );
});

it('links the counter-leg id PairLookup returns, tie-break included', function (): void {
    $rawPayload = [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [
            [
                'type' => 'Bankstorting',
                'row' => [
                    'Naam' => 'Withdraw to bank NL57ASNB0123456789',
                    'Reference Txn ID' => 'TXN-PARITY',
                ],
            ],
        ],
    ];

    $paypalTx = Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->paypal->id,
        'type' => TransactionType::TransferOut->value,
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -7350,
        'currency' => 'EUR',
        'settled_amount_minor' => -7350,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Bankstorting destination',
        'counterparty_normalized' => 'paypal-withdrawal',
        'normalization_version' => 3,
        'source_format' => 'paypal-csv',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'raw_payload' => $rawPayload,
        'fingerprint' => str_pad('clp-pp', 64, 'p', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);

    // Two candidates on the funding account, both inside the window and both on
    // the amount: only the closest-by-date tie-break separates them, which is
    // the part of the query a re-implementation is most likely to get wrong.
    $closer = counterLegParityTransferIn($this->user, $this->asn, $this->run, 7350, '2026-05-16', 'clp-near');
    counterLegParityTransferIn($this->user, $this->asn, $this->run, 7350, '2026-05-13', 'clp-far');

    /** @var PaypalFundingResolver $resolver */
    $resolver = $this->app->make(PaypalFundingResolver::class);
    $resolver->resolveForUser($this->user);

    /** @var PairLookup $lookup */
    $lookup = $this->app->make(PairLookup::class);
    $viaLookup = $lookup->counterLegOnAccount(
        new CounterLegMatch(
            accountId: $this->asn->id,
            amountMinor: -$paypalTx->amount_minor,
            types: [TransactionType::TransferIn],
            currency: 'EUR',
            unpairedOnly: false,
            excludeTransactionId: null,
        ),
        new CounterLegWindow(CarbonImmutable::parse($paypalTx->booked_at), CounterLegWindow::DEFAULT_DAYS, CounterLegOrder::NearestToCentre),
        $this->user,
    );

    expect($viaLookup)->toBe($closer->id);

    /** @var ChainLink $link */
    $link = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->where('kind', ChainLinkKind::PaypalFunding->value)
        ->firstOrFail();

    // Two answers to one question is not a deterministic match, so the arm
    // hands it to the review queue rather than confirming it. Which of the two
    // it names is still PairLookup's answer, which is what this file pins.
    expect($link->state)->toBe(ChainLinkState::Candidate->value);
    expect((int) $link->to_transaction_id)->toBe($viaLookup);
});
