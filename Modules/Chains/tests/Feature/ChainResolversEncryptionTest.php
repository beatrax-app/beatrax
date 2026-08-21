<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// Every seeded row goes through RecordTransactions rather than
// Transaction::create(), so counterparty_iban is real ciphertext at rest:
// a decrypt-of-plaintext no-op would otherwise pass for the wrong reason.

/**
 * @param  array<string, mixed>  $overrides
 */
function creCanonical(array $overrides): CanonicalTransaction
{
    $defaults = [
        'userId' => null,
        'accountId' => 1,
        'type' => 'expense',
        'postedAt' => CarbonImmutable::parse('2026-04-30'),
        'bookedAt' => CarbonImmutable::parse('2026-04-30 12:00:00'),
        'valueDate' => CarbonImmutable::parse('2026-04-30'),
        'amountMinor' => -1399,
        'currency' => 'EUR',
        'settledAmountMinor' => -1399,
        'settledCurrency' => 'EUR',
        'fxRateUsed' => null,
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
        fxRateUsed: $merged['fxRateUsed'],
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
        'username' => 'cre-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->session = $this->enablesEncryptionForUser($this->user);

    $this->bank = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN cre',
        'slug' => 'cre-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $this->paypal = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'PayPal cre',
        'slug' => 'cre-paypal-'.bin2hex(random_bytes(4)),
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    $this->icsCard = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS cre',
        'slug' => 'cre-ics-'.bin2hex(random_bytes(4)),
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/cre.csv',
        'sha256' => hash('sha256', 'cre-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->recorder = $this->app->make(RecordTransactions::class);
    $this->db = $this->app->make(DatabaseManager::class);
});

it('IcsSettlementResolver resolves the ASN→ICS bulk-settle chain under an encrypted user', function (): void {
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'NL08ABNA0526650664',
        'target_account_kind' => 'ics_card',
    ]);

    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsCard->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 10000,
        'state' => 'open',
    ]);

    ($this->recorder)([creCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->icsCard->id,
        'type' => 'expense',
        'postedAt' => CarbonImmutable::parse('2026-04-15'),
        'bookedAt' => CarbonImmutable::parse('2026-04-15 12:00:00'),
        'valueDate' => CarbonImmutable::parse('2026-04-15'),
        'amountMinor' => -10000,
        'settledAmountMinor' => -10000,
        'importRunId' => $this->run->id,
        'sourceRef' => 'ics-expense',
    ])], $this->user);

    // ASN-side transfer_out whose counterparty_iban is the ICS
    // institution IBAN — this is the ciphertext column under test.
    ($this->recorder)([creCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'transfer_out',
        'postedAt' => CarbonImmutable::parse('2026-05-02'),
        'bookedAt' => CarbonImmutable::parse('2026-05-02 12:00:00'),
        'valueDate' => CarbonImmutable::parse('2026-05-02'),
        'amountMinor' => -10000,
        'settledAmountMinor' => -10000,
        'counterpartyIban' => 'NL08ABNA0526650664',
        'importRunId' => $this->run->id,
        'sourceRowIndex' => 1,
        'sourceRef' => 'asn-transfer-out',
    ])], $this->user);

    // The pre-fix ciphertext-equality resolveAccount() found zero rows against
    // this fixture, so assert the column really is ciphertext at rest.
    $storedAsnTx = $this->db->connection()->table('transactions')
        ->where('account_id', $this->bank->id)->first();
    expect($storedAsnTx->counterparty_iban)->not->toBe('NL08ABNA0526650664');

    /** @var Transaction $transferOut */
    $transferOut = Transaction::query()->where('account_id', $this->bank->id)->firstOrFail();
    /** @var Transaction $expense */
    $expense = Transaction::query()->where('account_id', $this->icsCard->id)->firstOrFail();

    $resolver = $this->app->make(IcsSettlementResolver::class);
    $resolver->resolveForUser($this->user);

    $link = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->where('kind', 'ics_bulk_settle')
        ->where('state', 'confirmed')
        ->first();

    expect($link)->not->toBeNull();
    expect($link->from_transaction_id)->toBe($transferOut->id);
    expect($link->to_transaction_id)->toBe($expense->id);
});

it('RetypeByAliasResolver retypes an expense whose counterparty_iban resolves via the alias bridge under an encrypted user', function (): void {
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);

    ($this->recorder)([creCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'expense',
        'amountMinor' => -1399,
        'settledAmountMinor' => -1399,
        'counterpartyIban' => 'LU89751000135104200E',
        'importRunId' => $this->run->id,
        'sourceRef' => 'retype-expense',
    ])], $this->user);

    $storedTx = $this->db->connection()->table('transactions')
        ->where('account_id', $this->bank->id)->first();
    expect($storedTx->counterparty_iban)->not->toBe('LU89751000135104200E');

    /** @var Transaction $tx */
    $tx = Transaction::query()->where('account_id', $this->bank->id)->firstOrFail();

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    $touched = $resolver->resolveForUser($this->user);

    expect($touched)->toBe(1);
    expect($tx->refresh()->type)->toBe('transfer_out');
});

it('RetypeByAliasResolver retypes an income row to transfer_in via the alias bridge under an encrypted user', function (): void {
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'NL08ABNA0526650664',
        'target_account_kind' => 'ics_card',
    ]);

    ($this->recorder)([creCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'income',
        'amountMinor' => 4567,
        'settledAmountMinor' => 4567,
        'counterpartyIban' => 'NL08ABNA0526650664',
        'importRunId' => $this->run->id,
        'sourceRef' => 'retype-income',
    ])], $this->user);

    /** @var Transaction $tx */
    $tx = Transaction::query()->where('account_id', $this->bank->id)->firstOrFail();

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    $touched = $resolver->resolveForUser($this->user);

    expect($touched)->toBe(1);
    expect($tx->refresh()->type)->toBe('transfer_in');
});

it('RetypeByAliasResolver is idempotent under an encrypted user — a second pass touches zero rows', function (): void {
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);

    ($this->recorder)([creCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'expense',
        'amountMinor' => -1399,
        'settledAmountMinor' => -1399,
        'counterpartyIban' => 'LU89751000135104200E',
        'importRunId' => $this->run->id,
        'sourceRef' => 'retype-idem',
    ])], $this->user);

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    expect($resolver->resolveForUser($this->user))->toBe(1);
    expect($resolver->resolveForUser($this->user))->toBe(0);
});

it('RetypeByAliasResolver never decrypts a single row when the user has no known-counterparty aliases (bounded scan)', function (): void {
    ($this->recorder)([creCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'expense',
        'amountMinor' => -1399,
        'settledAmountMinor' => -1399,
        'counterpartyIban' => 'LU89751000135104200E',
        'importRunId' => $this->run->id,
        'sourceRef' => 'retype-no-alias',
    ])], $this->user);

    /** @var Transaction $tx */
    $tx = Transaction::query()->where('account_id', $this->bank->id)->firstOrFail();

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    $touched = $resolver->resolveForUser($this->user);

    expect($touched)->toBe(0);
    expect($tx->refresh()->type)->toBe('expense');
});

it('RetypeByAliasResolver skips a row whose alias resolves to its OWN account under an encrypted user (self-transfer guard)', function (): void {
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'NL99TESTSELF',
        'target_account_kind' => 'bank',
    ]);

    ($this->recorder)([creCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->bank->id,
        'type' => 'expense',
        'amountMinor' => -1399,
        'settledAmountMinor' => -1399,
        'counterpartyIban' => 'NL99TESTSELF',
        'importRunId' => $this->run->id,
        'sourceRef' => 'retype-self',
    ])], $this->user);

    /** @var Transaction $tx */
    $tx = Transaction::query()->where('account_id', $this->bank->id)->firstOrFail();

    $resolver = $this->app->make(RetypeByAliasResolver::class);
    $touched = $resolver->resolveForUser($this->user);

    expect($touched)->toBe(0);
    expect($tx->refresh()->type)->toBe('expense');
});
