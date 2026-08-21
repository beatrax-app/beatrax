<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

function seedAsnAccount(User $user, string $iban = 'NL57ASNB0123456789', string $slug = 'pf-asn'): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN fixture for PayPal funder',
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

function seedPaypalAccount(User $user, string $slug = 'pf-paypal'): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal fixture',
        'slug' => $slug,
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  array<int|string, mixed>|null  $rawPayload
 */
function seedPaypalTxOut(
    User $user,
    Account $paypal,
    ImportRun $run,
    int $amountMinor,
    ?array $rawPayload,
    string $counterpartyNormalized = 'paypal-withdrawal',
    string $postedAt = '2026-05-15',
    string $fingerprintSeed = 'pfo1',
): Transaction {
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $paypal->id,
        'type' => 'transfer_out',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => -$amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Bankstorting destination',
        'counterparty_normalized' => $counterpartyNormalized,
        'normalization_version' => 3,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'raw_payload' => $rawPayload,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'p', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

function seedPaypalExpense(
    User $user,
    Account $paypal,
    ImportRun $run,
    int $amountMinor,
    string $counterpartyNormalized,
    string $postedAt = '2026-05-15',
    string $fingerprintSeed = 'pfe1',
    string $counterpartyName = 'Some merchant',
): Transaction {
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $paypal->id,
        'type' => 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => -$amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$amountMinor,
        'settled_currency' => 'EUR',
        // The fuzzy arm scores the readable name, because the normalised
        // column is a keyed digest once at-rest encryption is on. Kept as the
        // bank's own spelling, which is the shape production stores.
        'counterparty_name' => $counterpartyName,
        'counterparty_normalized' => $counterpartyNormalized,
        'normalization_version' => 3,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 2,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'e', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

function seedAsnTransferIn(
    User $user,
    Account $asn,
    ImportRun $run,
    int $amountMinor,
    string $counterpartyNormalized = 'paypal',
    string $postedAt = '2026-05-15',
    string $fingerprintSeed = 'asn1',
    string $counterpartyName = 'PayPal',
): Transaction {
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $asn->id,
        'type' => 'transfer_in',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => $counterpartyName,
        'counterparty_normalized' => $counterpartyNormalized,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 7,
        'fingerprint' => str_pad($fingerprintSeed, 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'paypal-resolver',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->asn = seedAsnAccount($this->user);
    $this->paypal = seedPaypalAccount($this->user);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/paypal-resolver.csv',
        'sha256' => str_repeat('p', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var PaypalFundingResolver $resolver */
    $resolver = $this->app->make(PaypalFundingResolver::class);
    $this->resolver = $resolver;
});

it('deterministic match: PayPal Bankstorting → ASN by IBAN-in-memo', function (): void {
    $rawPayload = [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [
            [
                'type' => 'Bankstorting',
                'row' => [
                    'Naam' => 'Withdraw to bank NL57ASNB0123456789',
                    'Reference Txn ID' => 'TXN-REF-42',
                ],
            ],
        ],
    ];

    $paypalTx = seedPaypalTxOut($this->user, $this->paypal, $this->run, 4250, $rawPayload);
    seedAsnTransferIn($this->user, $this->asn, $this->run, 4250);

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink|null $link */
    $link = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->where('kind', 'paypal_funding')
        ->first();

    expect($link)->not->toBeNull();
    expect($link->state)->toBe('confirmed');
    expect((float) $link->confidence)->toBe(1.0);
    expect($link->resolver)->toBe('auto');
    expect($link->from_transaction_id)->toBe((int) $paypalTx->id);
    expect($link->evidence)->toBeArray();
    expect($link->evidence['matched_iban'])->toBe('NL57ASNB0123456789');
    expect($link->evidence['event_type'])->toBe('Bankstorting');
    expect($link->evidence['matched_reference_id'])->toBe('TXN-REF-42');
    expect($link->evidence['signature_hash'])->toMatch('/^[0-9a-f]{64}$/');
});

it('fuzzy match: PayPal expense without rawPayload yields candidate in [0.6, 0.99]', function (): void {
    // An exact merchant match would score 1.0 on every component; the slight
    // variation keeps the weighted score inside the candidate band instead of
    // looking deterministic.
    $expense = seedPaypalExpense($this->user, $this->paypal, $this->run, 1999, 'spotify ab', counterpartyName: 'Spotify AB');
    seedAsnTransferIn($this->user, $this->asn, $this->run, 1999, 'spotyfi ab', '2026-05-16', counterpartyName: 'Spotyfi AB');

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink|null $link */
    $link = ChainLink::query()->where('user_id', $this->user->id)->first();
    expect($link)->not->toBeNull();
    expect($link->state)->toBe('candidate');
    expect($link->from_transaction_id)->toBe((int) $expense->id);
    $confidence = (float) $link->confidence;
    expect($confidence)->toBeGreaterThanOrEqual(0.6);
    expect($confidence)->toBeLessThan(1.0);
    expect($link->evidence)->toBeArray();
    expect($link->evidence)->toHaveKey('merchant_similarity');
    expect($link->evidence)->toHaveKey('amount_delta_minor');
    expect($link->evidence)->toHaveKey('date_delta_days');
    expect($link->evidence)->toHaveKey('signature_hash');
    expect($link->evidence['signature_hash'])->toMatch('/^[0-9a-f]{64}$/');
});

it('drops fuzzy candidates whose weighted score < 0.6', function (): void {
    // Very different merchant + amount band still ok + date ok →
    // merchant_sim ≈ 0, so weighted score ≈ 0.5 + 0.2 ≈ 0.5 < 0.6.
    seedPaypalExpense($this->user, $this->paypal, $this->run, 1000, 'aaaaaaaaaa');
    seedAsnTransferIn($this->user, $this->asn, $this->run, 1000, 'zzzzzzzzzz');

    $this->resolver->resolveForUser($this->user);

    expect(ChainLink::query()->count())->toBe(0);
});

it('deterministic preempts fuzzy for the same PayPal row', function (): void {
    $rawPayload = [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [
            [
                'type' => 'Bankstorting',
                'row' => [
                    'Naam' => 'Withdrawal NL57ASNB0123456789',
                    'Reference Txn ID' => 'TXN-DUAL',
                ],
            ],
        ],
    ];
    seedPaypalTxOut($this->user, $this->paypal, $this->run, 5000, $rawPayload);
    // Also seed a strong fuzzy candidate on ASN — should be ignored.
    seedAsnTransferIn($this->user, $this->asn, $this->run, 5000);

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink|null $link */
    $link = ChainLink::query()->where('user_id', $this->user->id)->first();
    expect($link)->not->toBeNull();
    expect($link->state)->toBe('confirmed');
    expect((float) $link->confidence)->toBe(1.0);
    expect(ChainLink::query()->count())->toBe(1);
});

it('signature_hash = sha256(normalized_merchant + | + funding_account_iban) on deterministic match', function (): void {
    $rawPayload = [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [
            ['type' => 'Bankstorting', 'row' => ['Naam' => 'NL57ASNB0123456789']],
        ],
    ];
    seedPaypalTxOut(
        $this->user,
        $this->paypal,
        $this->run,
        1234,
        $rawPayload,
        counterpartyNormalized: 'bankstorting',
    );
    seedAsnTransferIn($this->user, $this->asn, $this->run, 1234);

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink $link */
    $link = ChainLink::query()->where('user_id', $this->user->id)->firstOrFail();
    $expected = hash('sha256', 'bankstorting|NL57ASNB0123456789');
    expect($link->evidence['signature_hash'])->toBe($expected);
});

it('does NOT mutate transactions rows', function (): void {
    $rawPayload = [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [
            ['type' => 'Bankstorting', 'row' => ['Naam' => 'NL57ASNB0123456789']],
        ],
    ];
    $tx = seedPaypalTxOut($this->user, $this->paypal, $this->run, 2500, $rawPayload);
    $asnTx = seedAsnTransferIn($this->user, $this->asn, $this->run, 2500);

    $txBefore = $tx->fresh()->updated_at;
    $asnBefore = $asnTx->fresh()->updated_at;

    sleep(1);

    $this->resolver->resolveForUser($this->user);

    expect($tx->fresh()->updated_at->toIso8601String())->toBe($txBefore->toIso8601String());
    expect($asnTx->fresh()->updated_at->toIso8601String())->toBe($asnBefore->toIso8601String());
});

it('isolates resolver by user — other users untouched', function (): void {
    $other = User::query()->create([
        'username' => 'paypal-resolver-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $otherAsn = seedAsnAccount($other, slug: 'pf-asn-other');
    $otherPaypal = seedPaypalAccount($other, slug: 'pf-paypal-other');
    $otherRun = ImportRun::query()->create([
        'user_id' => $other->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/other.csv',
        'sha256' => str_repeat('o', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $rawPayload = [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [
            ['type' => 'Bankstorting', 'row' => ['Naam' => 'NL57ASNB0123456789']],
        ],
    ];

    // Same-IBAN PayPal row on OTHER user.
    seedPaypalTxOut(
        $other,
        $otherPaypal,
        $otherRun,
        1500,
        $rawPayload,
        fingerprintSeed: 'po2',
    );
    seedAsnTransferIn($other, $otherAsn, $otherRun, 1500, fingerprintSeed: 'ao2');

    seedPaypalTxOut($this->user, $this->paypal, $this->run, 1500, $rawPayload);
    seedAsnTransferIn($this->user, $this->asn, $this->run, 1500);

    $this->resolver->resolveForUser($this->user);

    expect(ChainLink::query()->where('user_id', $this->user->id)->count())->toBe(1);
    expect(ChainLink::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('is idempotent — re-running resolveForUser produces zero additional chain_links', function (): void {
    $rawPayload = [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [
            ['type' => 'Bankstorting', 'row' => ['Naam' => 'NL57ASNB0123456789']],
        ],
    ];
    seedPaypalTxOut($this->user, $this->paypal, $this->run, 3300, $rawPayload);
    seedAsnTransferIn($this->user, $this->asn, $this->run, 3300);

    $this->resolver->resolveForUser($this->user);
    $first = ChainLink::query()->count();
    $this->resolver->resolveForUser($this->user);
    $second = ChainLink::query()->count();

    expect($first)->toBe(1);
    expect($second)->toBe($first);
});

it('keeps rejected pairs rejected on re-run (pre-insert pair guard)', function (): void {
    $rawPayload = [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [
            ['type' => 'Bankstorting', 'row' => ['Naam' => 'NL57ASNB0123456789']],
        ],
    ];
    seedPaypalTxOut($this->user, $this->paypal, $this->run, 4400, $rawPayload);
    seedAsnTransferIn($this->user, $this->asn, $this->run, 4400);

    $this->resolver->resolveForUser($this->user);
    /** @var ChainLink $link */
    $link = ChainLink::query()->where('user_id', $this->user->id)->firstOrFail();
    $link->state = 'rejected';
    $link->save();

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink $after */
    $after = ChainLink::query()->where('id', $link->id)->firstOrFail();
    expect($after->state)->toBe('rejected');
    expect(ChainLink::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('handles PayPal rows with empty events[] gracefully — falls back to fuzzy', function (): void {
    $rawPayload = [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [],
    ];
    seedPaypalTxOut(
        $this->user,
        $this->paypal,
        $this->run,
        1000,
        $rawPayload,
        counterpartyNormalized: 'aaaaaaaaaa',
    );
    seedAsnTransferIn($this->user, $this->asn, $this->run, 1000, 'zzzzzzzzzz');

    $this->resolver->resolveForUser($this->user);

    expect(ChainLink::query()->count())->toBe(0);
});

it('handles PayPal rows with null raw_payload (no events) gracefully', function (): void {
    seedPaypalExpense($this->user, $this->paypal, $this->run, 1000, 'aaaaaaaaaa');
    seedAsnTransferIn($this->user, $this->asn, $this->run, 1000, 'zzzzzzzzzz');

    expect(fn () => $this->resolver->resolveForUser($this->user))->not->toThrow(Throwable::class);
});

// levenshteinSimilarity('', '') is 1.0 — two empty strings really are
// identical. Feeding it a value that means "unknown" made two rows whose
// merchant nobody could read score a PERFECT match, and the resolver preferred
// them: a readable candidate scores 0.0 against an unreadable source.
it('writes no fuzzy link when neither side has a readable merchant name', function (): void {
    seedPaypalExpense($this->user, $this->paypal, $this->run, 1999, 'spotify ab', counterpartyName: '');
    seedAsnTransferIn($this->user, $this->asn, $this->run, 1999, 'spotyfi ab', '2026-05-16', counterpartyName: '');

    $this->resolver->resolveForUser($this->user);

    expect(ChainLink::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

it('writes no fuzzy link when only the candidate is unreadable', function (): void {
    seedPaypalExpense($this->user, $this->paypal, $this->run, 1999, 'spotify ab', counterpartyName: 'Spotify AB');
    seedAsnTransferIn($this->user, $this->asn, $this->run, 1999, 'spotyfi ab', '2026-05-16', counterpartyName: '');

    $this->resolver->resolveForUser($this->user);

    expect(ChainLink::query()->where('user_id', $this->user->id)->count())->toBe(0);
});
