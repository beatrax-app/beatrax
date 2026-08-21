<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Import\Models\KnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Tests\Support\EnablesEncryptionForUser;

uses(RefreshDatabase::class, EnablesEncryptionForUser::class);

// Pre-fix this scenario returned empty under an encrypted user: the
// deterministic arm's raw query-builder read bypassed the Eloquent cast, and
// the ASN-direct arm's JOIN compared against ciphertext. Rows are written
// through RecordTransactions so both columns are genuinely encrypted at rest.

/**
 * @param  array<string, mixed>  $overrides
 */
function rpdCanonical(array $overrides): CanonicalTransaction
{
    $defaults = [
        'userId' => null,
        'accountId' => 1,
        'type' => 'expense',
        'postedAt' => CarbonImmutable::parse('2026-05-15'),
        'bookedAt' => CarbonImmutable::parse('2026-05-15 12:00:00'),
        'valueDate' => CarbonImmutable::parse('2026-05-15'),
        'amountMinor' => -4250,
        'currency' => 'EUR',
        'settledAmountMinor' => -4250,
        'settledCurrency' => 'EUR',
        'fxRateUsed' => null,
        'counterpartyName' => 'PayPal',
        'counterpartyIban' => null,
        'counterpartyNormalized' => 'paypal',
        'normalizationVersion' => 3,
        'description' => null,
        'categoryId' => null,
        'sourceFormat' => 'paypal-csv',
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
        'username' => 'rpd-resolver-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->session = $this->enablesEncryptionForUser($this->user);

    $this->asn = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN rpd resolver',
        'slug' => 'rpd-resolver-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $this->paypal = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'PayPal rpd resolver',
        'slug' => 'rpd-resolver-paypal-'.bin2hex(random_bytes(4)),
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/rpd-resolver.csv',
        'sha256' => hash('sha256', 'rpd-resolver-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->recorder = $this->app->make(RecordTransactions::class);
    $this->resolver = $this->app->make(PaypalFundingResolver::class);
    $this->db = $this->app->make(DatabaseManager::class);
});

it('deterministic arm resolves under an encrypted user (raw_payload decrypted off the raw query-builder read)', function (): void {
    $rawPayload = [
        'events' => [[
            'type' => 'Bankstorting',
            'row' => [
                'Naam' => 'Withdraw to bank NL57ASNB0123456789',
                'Reference Txn ID' => 'TXN-REF-RPD',
            ],
        ]],
    ];

    ($this->recorder)([rpdCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->paypal->id,
        'type' => 'transfer_out',
        'importRunId' => $this->run->id,
        'rawPayload' => $rawPayload,
    ])], $this->user);

    ($this->recorder)([rpdCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->asn->id,
        'type' => 'transfer_in',
        'amountMinor' => 4250,
        'settledAmountMinor' => 4250,
        'importRunId' => $this->run->id,
        'counterpartyNormalized' => 'paypal',
        'sourceFormat' => 'asn-csv',
    ])], $this->user);

    // Confirm the row is genuinely ciphertext at rest — a pre-fix decrypt-
    // of-plaintext no-op would pass this test for the wrong reason.
    $storedPaypalTx = $this->db->connection()->table('transactions')
        ->where('account_id', $this->paypal->id)->first();
    expect($storedPaypalTx->raw_payload)->not->toBe(json_encode($rawPayload));

    $this->resolver->resolveForUser($this->user);

    $link = ChainLink::query()->where('user_id', $this->user->id)->where('kind', 'paypal_funding')->first();
    expect($link)->not->toBeNull();
    expect($link->state)->toBe('confirmed');
    expect((float) $link->confidence)->toBe(1.0);
    expect($link->evidence['matched_iban'])->toBe('NL57ASNB0123456789');
});

it('ASN-direct arm resolves under an encrypted user (counterparty_iban decrypt-then-match against the plaintext alias set)', function (): void {
    KnownCounterpartyIban::withoutGlobalScopes()->create([
        'user_id' => $this->user->id,
        'real_iban' => 'LU89751000135104200E',
        'target_account_kind' => 'paypal',
    ]);

    ($this->recorder)([rpdCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->paypal->id,
        'type' => 'expense',
        'amountMinor' => -1399,
        'settledAmountMinor' => -1399,
        'importRunId' => $this->run->id,
        'counterpartyName' => 'Google Cloud EMEA Limited',
        'counterpartyNormalized' => 'GOOGLE CLOUD EMEA LIMITED',
        'sourceRef' => 'ppe-1',
    ])], $this->user);

    ($this->recorder)([rpdCanonical([
        'userId' => $this->user->id,
        'accountId' => $this->asn->id,
        'type' => 'transfer_out',
        'amountMinor' => -1399,
        'settledAmountMinor' => -1399,
        'importRunId' => $this->run->id,
        'counterpartyName' => 'PayPal Europe S.a.r.l. et Cie S.C.A',
        'counterpartyIban' => 'LU89751000135104200E',
        'counterpartyNormalized' => 'PAYPAL EUROPE SARL ET CIE SCA',
        'sourceFormat' => 'camt053',
        'sourceRef' => 'asn-1',
    ])], $this->user);

    // The pre-fix ciphertext-equality JOIN found zero rows against this
    // fixture, so assert the column really is ciphertext at rest.
    $storedAsnTx = $this->db->connection()->table('transactions')
        ->where('account_id', $this->asn->id)->first();
    expect($storedAsnTx->counterparty_iban)->not->toBe('LU89751000135104200E');

    $this->resolver->resolveForUser($this->user);

    $link = ChainLink::query()->where('user_id', $this->user->id)->where('kind', 'paypal_funding')->first();
    expect($link)->not->toBeNull();
    expect($link->state)->toBe('confirmed');
    expect((float) $link->confidence)->toBe(1.0);
    expect($link->evidence['matched_via'])->toBe('asn_alias_amount_date');

    // The IBAN this arm matched on is a COUNTERPARTY's, decrypted out of a
    // column sealed to protect it. `chain_links.evidence` is not encrypted, so
    // writing it back here hands it to anyone reading the file.
    $stored = (string) $this->db->connection()->table('chain_links')->where('id', $link->id)->value('evidence');
    expect($stored)->not->toContain('LU89751000135104200E');
});
