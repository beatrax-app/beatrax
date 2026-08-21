<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\CardStatementCredit;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

/**
 * @return array{0: list<int>, 1: int} List of inserted transaction ids
 *                                     AND the actual computed sum (matches the constant).
 */
function seedIcsExpenses(User $user, Account $icsAccount, ImportRun $run, int $count, int $totalAbsMinor): array
{
    // Split the total across $count rows. The first $count-1 rows each
    // get the rounded-down quotient; the last row absorbs the
    // remainder so the sum equals the requested total exactly.
    $each = intdiv($totalAbsMinor, $count);
    $remainder = $totalAbsMinor - ($each * ($count - 1));
    $ids = [];
    $sum = 0;
    for ($i = 1; $i <= $count; $i++) {
        $absRow = $i === $count ? $remainder : $each;
        $signed = -$absRow;
        $tx = Transaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $icsAccount->id,
            'type' => 'expense',
            'posted_at' => '2026-05-'.str_pad((string) min(28, max(1, $i)), 2, '0', STR_PAD_LEFT),
            'booked_at' => '2026-05-'.str_pad((string) min(28, max(1, $i)), 2, '0', STR_PAD_LEFT).' 12:00:00',
            'value_date' => '2026-05-'.str_pad((string) min(28, max(1, $i)), 2, '0', STR_PAD_LEFT),
            'amount_minor' => $signed,
            'currency' => 'EUR',
            'settled_amount_minor' => $signed,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Merchant '.$i,
            'counterparty_normalized' => 'merchant-'.$i,
            'normalization_version' => 1,
            'source_format' => 'ics-pdf',
            'import_run_id' => $run->id,
            'source_row_index' => $i,
            'fingerprint' => str_pad('exp'.$i, 64, 'e', STR_PAD_LEFT),
            'fingerprint_version' => 3,
        ]);
        $ids[] = (int) $tx->id;
        $sum += $absRow;
    }

    return [$ids, $sum];
}

// The ASN-side transfer_out's settled_amount_minor is negative (money leaving
// the bank); the resolver inverts the sign at the algorithm boundary so the
// rest of the maths runs in the positive ICS-side convention.
function seedTransferIn(User $user, Account $bank, ImportRun $run, int $settledMinor, string $postedAt = '2026-05-29'): Transaction
{
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $bank->id,
        'type' => 'transfer_out',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => -$settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_iban' => 'NL08ABNA0526650664',
        'counterparty_name' => 'ICS Bulk Settlement',
        'counterparty_normalized' => 'ics-bulk-settlement',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 9999,
        'fingerprint' => str_pad('tin', 64, 't', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->conn = $db->connection();

    $this->user = User::query()->create([
        'username' => 'ics-resolver',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->icsAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS resolver fixture',
        'slug' => 'ics-resolver-fixture',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    // The resolver iterates the ASN side: the bulk-iDEAL transfer_out posts
    // here, not on the ICS account.
    $this->bankAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN bank fixture',
        'slug' => 'asn-bank-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    // The canonical alias rows (LU…E → paypal, NL08ABNA… → ics_card) are the
    // bridge from the transfer_out's counterparty IBAN to the ics_card account.
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($this->user);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/ics-resolver.pdf',
        'sha256' => str_repeat('r', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->asnRun = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ics-resolver-asn.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // Seed 23 expense rows summing to €847.32 (84732 minor cents).
    [$expenseIds, $sum] = seedIcsExpenses($this->user, $this->icsAccount, $this->run, 23, 84732);
    $this->expenseIds = $expenseIds;
    expect($sum)->toBe(84732); // sanity — fixture is wired correctly

    $statement = CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 23:59:59',
        'total_amount_minor' => -84732,
        'open_balance_minor' => 84732,
        'state' => 'open',
    ]);
    $this->statementId = (int) $statement->id;

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $this->resolver = $resolver;
});

dataset('bulk_settle_variants', [
    'clean' => ['settledMinor' => 84732, 'expectedState' => 'settled',           'expectedChainState' => 'confirmed', 'expectedCreditRows' => 0, 'expectedToleranceUsed' => 'amount_5eur'],
    'overpaid_1.53' => ['settledMinor' => 84885, 'expectedState' => 'overpaid',          'expectedChainState' => 'confirmed', 'expectedCreditRows' => 1, 'expectedToleranceUsed' => 'amount_5eur'],
    'underpaid_2.18' => ['settledMinor' => 84514, 'expectedState' => 'partially_settled', 'expectedChainState' => 'confirmed', 'expectedCreditRows' => 0, 'expectedToleranceUsed' => 'amount_5eur'],
    'exceed_50.00' => ['settledMinor' => 89732, 'expectedState' => 'open',              'expectedChainState' => 'candidate', 'expectedCreditRows' => 0, 'expectedToleranceUsed' => 'exceeded'],
]);

it('decomposes ICS bulk-iDEAL settlement per D-97 tolerance arms', function (
    int $settledMinor,
    string $expectedState,
    string $expectedChainState,
    int $expectedCreditRows,
    string $expectedToleranceUsed,
): void {
    seedTransferIn($this->user, $this->bankAccount, $this->asnRun, $settledMinor);

    $this->resolver->resolveForUser($this->user);

    $chainLinks = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->where('kind', 'ics_bulk_settle')
        ->get();

    if ($expectedChainState === 'confirmed') {
        expect($chainLinks)->toHaveCount(23);
        expect($chainLinks->pluck('state')->unique()->values()->toArray())->toBe(['confirmed']);
        expect($chainLinks->pluck('confidence')->unique()->map(fn ($c) => (float) $c)->values()->toArray())->toBe([1.0]);
    } else {
        expect($chainLinks)->toHaveCount(1);
        expect($chainLinks->first()->state)->toBe('candidate');
        $confidence = (float) $chainLinks->first()->confidence;
        expect($confidence)->toBeGreaterThanOrEqual(0.6);
        expect($confidence)->toBeLessThan(1.0);
        expect($chainLinks->first()->to_transaction_id)->toBeNull();
    }

    /** @var CardStatement $statement */
    $statement = CardStatement::query()->findOrFail($this->statementId);
    expect($statement->state)->toBe($expectedState);

    $credits = CardStatementCredit::query()->where('user_id', $this->user->id)->get();
    expect($credits)->toHaveCount($expectedCreditRows);
    if ($expectedCreditRows > 0) {
        $credit = $credits->first();
        expect($credit->reason)->toBe('overpayment');
        // Overpayment magnitude equals (settled − statement total).
        $expectedMagnitude = abs($settledMinor - 84732);
        expect((int) $credit->amount_minor)->toBe($expectedMagnitude);
        expect($credit->from_statement_id)->toBe($this->statementId);
        expect($credit->to_statement_id)->toBeNull();
    }

    // Every chain_link carries the same evidence shape, so one sample suffices.
    /** @var ChainLink $sample */
    $sample = $chainLinks->first();
    $evidence = $sample->evidence;
    expect($evidence)->toBeArray();
    expect($evidence)->toHaveKey('statement_id');
    expect($evidence)->toHaveKey('unaccounted_delta_minor');
    expect($evidence)->toHaveKey('tolerance_used');
    expect($evidence)->toHaveKey('covered_count');
    expect($evidence)->toHaveKey('credits_applied_minor');
    expect($evidence)->toHaveKey('signature_hash');
    expect($evidence['tolerance_used'])->toBe($expectedToleranceUsed);
})->with('bulk_settle_variants');

it('is idempotent — re-running resolveForUser produces zero additional chain_links', function (): void {
    seedTransferIn($this->user, $this->bankAccount, $this->asnRun, 84732);

    $this->resolver->resolveForUser($this->user);
    $countAfterFirst = ChainLink::query()->where('user_id', $this->user->id)->count();
    expect($countAfterFirst)->toBe(23);

    $this->resolver->resolveForUser($this->user);
    $countAfterSecond = ChainLink::query()->where('user_id', $this->user->id)->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('keeps rejected pairs rejected on re-run (pair-uniqueness pre-insert guard)', function (): void {
    seedTransferIn($this->user, $this->bankAccount, $this->asnRun, 84732);

    $this->resolver->resolveForUser($this->user);

    $first = ChainLink::query()->where('user_id', $this->user->id)->first();
    expect($first)->not->toBeNull();
    $first->state = 'rejected';
    $first->save();

    // The pre-insert pair-uniqueness guard checks every state, so no fresh row
    // is written for an already-rejected pair.
    $this->resolver->resolveForUser($this->user);

    $stillRejected = ChainLink::query()->where('id', $first->id)->first();
    expect($stillRejected->state)->toBe('rejected');

    expect(ChainLink::query()->where('user_id', $this->user->id)->count())->toBe(23);
});

it('does NOT mutate transactions rows (D-84 invariant)', function (): void {
    $transfer = seedTransferIn($this->user, $this->bankAccount, $this->asnRun, 84732);

    $transferUpdatedBefore = $transfer->fresh()->updated_at;
    /** @var Transaction $sampleExpense */
    $sampleExpense = Transaction::query()->whereIn('id', $this->expenseIds)->first();
    $expenseUpdatedBefore = $sampleExpense->updated_at;

    sleep(1); // Force any unwanted updated_at write to land on a later second

    $this->resolver->resolveForUser($this->user);

    expect($transfer->fresh()->updated_at->toIso8601String())
        ->toBe($transferUpdatedBefore->toIso8601String());
    expect($sampleExpense->fresh()->updated_at->toIso8601String())
        ->toBe($expenseUpdatedBefore->toIso8601String());
});

it('isolates the resolver by user — other users are untouched', function (): void {
    $otherUser = User::query()->create([
        'username' => 'ics-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $otherAccount = Account::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'Other ICS',
        'slug' => 'other-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $otherRun = ImportRun::query()->create([
        'user_id' => $otherUser->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/other.pdf',
        'sha256' => str_repeat('o', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    seedIcsExpenses($otherUser, $otherAccount, $otherRun, 5, 12345);
    CardStatement::query()->create([
        'user_id' => $otherUser->id,
        'account_id' => $otherAccount->id,
        'import_run_id' => $otherRun->id,
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 23:59:59',
        'total_amount_minor' => -12345,
        'open_balance_minor' => 12345,
        'state' => 'open',
    ]);

    seedTransferIn($this->user, $this->bankAccount, $this->asnRun, 84732);

    $this->resolver->resolveForUser($this->user);

    expect(ChainLink::query()->where('user_id', $this->user->id)->count())->toBe(23);
    expect(ChainLink::query()->where('user_id', $otherUser->id)->count())->toBe(0);
});

it('handles refund-after-close per D-98', function (): void {
    // Not EUR: a writer that omitted the credit's currency would still land EUR
    // from the column default, which would prove nothing about the credit.
    CardStatement::query()->whereKey($this->statementId)->update(['currency' => 'USD']);

    seedTransferIn($this->user, $this->bankAccount, $this->asnRun, 84732);
    $this->resolver->resolveForUser($this->user);

    /** @var CardStatement $may */
    $may = CardStatement::query()->findOrFail($this->statementId);
    expect($may->state)->toBe('settled');

    // Stage 2: create a NEXT open statement (June) so the refund
    // credit has a destination.
    $june = CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-06-01 00:00:00',
        'period_end' => '2026-06-30 23:59:59',
        'total_amount_minor' => -50000,
        'open_balance_minor' => 50000,
        'state' => 'open',
    ]);

    // The refund must match a seeded expense on counterparty_normalized +
    // amount; every seeded row carries -3684 minor (84732/23) and the first
    // is `merchant-1`.
    /** @var Transaction $original */
    $original = Transaction::query()->find($this->expenseIds[0]);
    $refund = Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'type' => 'refund',
        'posted_at' => '2026-05-20',
        'booked_at' => '2026-05-20 12:00:00',
        'value_date' => '2026-05-20',
        'amount_minor' => -$original->settled_amount_minor,   // positive
        'currency' => 'EUR',
        'settled_amount_minor' => -$original->settled_amount_minor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Merchant 1',
        'counterparty_normalized' => 'merchant-1',
        'normalization_version' => 1,
        'source_format' => 'ics-pdf',
        'import_run_id' => $this->run->id,
        'source_row_index' => 4242,
        'fingerprint' => str_pad('rfd', 64, 'r', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink|null $refundLink */
    $refundLink = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->where('from_transaction_id', $refund->id)
        ->where('kind', 'ics_bulk_settle')
        ->first();
    expect($refundLink)->not->toBeNull();
    expect($refundLink->to_transaction_id)->toBe((int) $original->id);
    expect($refundLink->state)->toBe('confirmed');
    expect($refundLink->evidence)->toHaveKey('reason');
    expect($refundLink->evidence['reason'])->toBe('refund_after_close');

    /** @var CardStatementCredit|null $credit */
    $credit = CardStatementCredit::query()
        ->where('user_id', $this->user->id)
        ->where('reason', 'refund_after_close')
        ->first();
    expect($credit)->not->toBeNull();
    expect($credit->from_statement_id)->toBe($this->statementId);
    expect($credit->to_statement_id)->toBe((int) $june->id);
    expect((int) $credit->amount_minor)->toBe(abs((int) $refund->settled_amount_minor));
    expect($credit->currency)->toBe('USD');
});
