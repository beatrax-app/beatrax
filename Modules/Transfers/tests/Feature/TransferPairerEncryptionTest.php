<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// Seeded through RecordTransactions, not Transaction::create(), so
// counterparty_iban is genuine ciphertext: against plaintext, a decrypt that
// did nothing would pass for the wrong reason.

/**
 * @param  array<string, mixed>  $overrides
 */
function tpCanonical(array $overrides): CanonicalTransaction
{
    $defaults = [
        'userId' => null,
        'accountId' => 1,
        'type' => 'transfer_out',
        'postedAt' => CarbonImmutable::parse('2026-04-30'),
        'bookedAt' => CarbonImmutable::parse('2026-04-30 12:00:00'),
        'valueDate' => CarbonImmutable::parse('2026-04-30'),
        'amountMinor' => -1399,
        'currency' => 'EUR',
        'settledAmountMinor' => -1399,
        'settledCurrency' => 'EUR',
        'counterpartyName' => 'fixture-counterparty',
        'counterpartyIban' => null,
        'counterpartyNormalized' => 'FIXTURE',
        'normalizationVersion' => 1,
        'description' => null,
        'categoryId' => null,
        'sourceFormat' => 'camt053',
        'importRunId' => 1,
        'sourceRowIndex' => 0,
        'sourceRef' => null,
        'rawPayload' => null,
    ];
    $merged = array_merge($defaults, $overrides);

    return new CanonicalTransaction(
        userId: $merged['userId'],
        accountId: $merged['accountId'],
        type: $merged['type'],
        postedAt: $merged['postedAt'],
        bookedAt: $merged['bookedAt'],
        valueDate: $merged['valueDate'],
        amountMinor: $merged['amountMinor'],
        currency: $merged['currency'],
        settledAmountMinor: $merged['settledAmountMinor'],
        settledCurrency: $merged['settledCurrency'],
        counterpartyName: $merged['counterpartyName'],
        counterpartyIban: $merged['counterpartyIban'],
        counterpartyNormalized: $merged['counterpartyNormalized'],
        normalizationVersion: $merged['normalizationVersion'],
        description: $merged['description'],
        categoryId: $merged['categoryId'],
        sourceFormat: $merged['sourceFormat'],
        importRunId: $merged['importRunId'],
        sourceRowIndex: $merged['sourceRowIndex'],
        sourceRef: $merged['sourceRef'],
        rawPayload: $merged['rawPayload'],
    );
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'tp-enc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->session = $this->enablesEncryptionForUser($this->user);

    $this->bank = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN tp-enc',
        'slug' => 'tp-enc-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $this->paypal = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'PayPal tp-enc',
        'slug' => 'tp-enc-paypal-'.bin2hex(random_bytes(4)),
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/tp-enc.csv',
        'sha256' => hash('sha256', 'tp-enc-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->recorder = $this->app->make(RecordTransactions::class);
    $this->db = $this->app->make(DatabaseManager::class);
});

// TransactionImported is dispatched synchronously, so pairOne() runs inline on
// whichever row lands second. Insert order therefore picks the arm: PayPal
// first exercises the forward arm, the iban-carrying leg first the reverse.

it('forward arm pairs via literal accounts.iban match under an encrypted user', function (): void {
    ($this->recorder)([tpCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->paypal->id,
        'type' => 'transfer_in',
        'amountMinor' => 1399,
        'settledAmountMinor' => 1399,
        'counterpartyIban' => null,
        'importRunId' => $this->run->id,
        'sourceRef' => 'paypal-literal',
    ])], $this->user);
    ($this->recorder)([tpCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'transfer_out',
        'counterpartyIban' => 'PAYPAL',
        'importRunId' => $this->run->id,
        'sourceRowIndex' => 1,
        'sourceRef' => 'asn-literal',
    ])], $this->user);

    $storedAsnTx = $this->db->connection()->table('transactions')
        ->where('account_id', $this->bank->id)->first();
    expect($storedAsnTx->counterparty_iban)->not->toBe('PAYPAL');

    /** @var Transaction $asnTx */
    $asnTx = Transaction::query()->where('account_id', $this->bank->id)->firstOrFail();
    /** @var Transaction $paypalTx */
    $paypalTx = Transaction::query()->where('account_id', $this->paypal->id)->firstOrFail();

    expect($asnTx->pair_transaction_id)->toBe($paypalTx->id);
    expect($paypalTx->pair_transaction_id)->toBe($asnTx->id);
});

it('forward arm pairs via the alias bridge under an encrypted user', function (): void {
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);

    ($this->recorder)([tpCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->paypal->id,
        'type' => 'transfer_in',
        'amountMinor' => 1399,
        'settledAmountMinor' => 1399,
        'counterpartyIban' => null,
        'importRunId' => $this->run->id,
        'sourceRef' => 'paypal-alias',
    ])], $this->user);
    ($this->recorder)([tpCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'transfer_out',
        'counterpartyIban' => 'LU89751000135104200E',
        'importRunId' => $this->run->id,
        'sourceRowIndex' => 1,
        'sourceRef' => 'asn-alias',
    ])], $this->user);

    $storedAsnTx = $this->db->connection()->table('transactions')
        ->where('account_id', $this->bank->id)->first();
    expect($storedAsnTx->counterparty_iban)->not->toBe('LU89751000135104200E');

    /** @var Transaction $asnTx */
    $asnTx = Transaction::query()->where('account_id', $this->bank->id)->firstOrFail();
    /** @var Transaction $paypalTx */
    $paypalTx = Transaction::query()->where('account_id', $this->paypal->id)->firstOrFail();

    expect($asnTx->pair_transaction_id)->toBe($paypalTx->id);
    expect($paypalTx->pair_transaction_id)->toBe($asnTx->id);
});

it('reverse arm pairs when the firing leg has no counterparty_iban (decrypt-then-match against the plaintext candidate set)', function (): void {
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);

    ($this->recorder)([tpCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'transfer_out',
        'counterpartyIban' => 'LU89751000135104200E',
        'importRunId' => $this->run->id,
        'sourceRef' => 'asn-reverse',
    ])], $this->user);
    ($this->recorder)([tpCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->paypal->id,
        'type' => 'transfer_in',
        'amountMinor' => 1399,
        'settledAmountMinor' => 1399,
        'counterpartyIban' => null,
        'importRunId' => $this->run->id,
        'sourceRowIndex' => 1,
        'sourceRef' => 'paypal-reverse',
    ])], $this->user);

    $storedAsnTx = $this->db->connection()->table('transactions')
        ->where('account_id', $this->bank->id)->first();
    expect($storedAsnTx->counterparty_iban)->not->toBe('LU89751000135104200E');

    /** @var Transaction $paypalTx */
    $paypalTx = Transaction::query()->where('account_id', $this->paypal->id)->firstOrFail();
    /** @var Transaction $asnTx */
    $asnTx = Transaction::query()->where('account_id', $this->bank->id)->firstOrFail();

    expect($paypalTx->pair_transaction_id)->toBe($asnTx->id);
    expect($asnTx->pair_transaction_id)->toBe($paypalTx->id);
});

it('reverse arm still narrows on the plaintext amount dimension before any decrypt (bounded candidate set)', function (): void {
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);

    // Decoy: the right iban, wrong amount. Picking it means the SQL narrowing
    // no longer runs before the PHP-side iban comparison.
    ($this->recorder)([tpCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'transfer_out',
        'amountMinor' => -9999,
        'settledAmountMinor' => -9999,
        'counterpartyIban' => 'LU89751000135104200E',
        'importRunId' => $this->run->id,
        'sourceRef' => 'asn-decoy',
    ])], $this->user);
    // Genuine match.
    ($this->recorder)([tpCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'transfer_out',
        'amountMinor' => -1399,
        'settledAmountMinor' => -1399,
        'counterpartyIban' => 'LU89751000135104200E',
        'importRunId' => $this->run->id,
        'sourceRowIndex' => 1,
        'sourceRef' => 'asn-genuine',
    ])], $this->user);
    ($this->recorder)([tpCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->paypal->id,
        'type' => 'transfer_in',
        'amountMinor' => 1399,
        'settledAmountMinor' => 1399,
        'counterpartyIban' => null,
        'importRunId' => $this->run->id,
        'sourceRowIndex' => 2,
        'sourceRef' => 'paypal-genuine',
    ])], $this->user);

    /** @var Transaction $paypalTx */
    $paypalTx = Transaction::query()->where('account_id', $this->paypal->id)->firstOrFail();
    /** @var Transaction $genuineAsnTx */
    $genuineAsnTx = Transaction::query()->where('amount_minor', -1399)->where('account_id', $this->bank->id)->firstOrFail();
    /** @var Transaction $decoyAsnTx */
    $decoyAsnTx = Transaction::query()->where('amount_minor', -9999)->firstOrFail();

    expect($paypalTx->pair_transaction_id)->toBe($genuineAsnTx->id);
    expect($decoyAsnTx->pair_transaction_id)->toBeNull();
});
