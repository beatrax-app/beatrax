<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\StatementSummary;
use Modules\Ledger\Models\Transaction;

function aliasIcsExpense(
    User $user,
    Account $icsAccount,
    ImportRun $run,
    int $absMinor,
    string $postedAt,
    int $rowIndex,
): Transaction {
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $icsAccount->id,
        'type' => 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => -$absMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$absMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'ICS Merchant '.$rowIndex,
        'counterparty_normalized' => 'ics-merchant-'.$rowIndex,
        'normalization_version' => 1,
        'source_format' => 'ics-pdf',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad('ae'.$rowIndex, 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

// $rowIndex varies the fingerprint base so one test can persist several
// transfer_out rows without colliding on the fingerprint UNIQUE.
function aliasAsnTransferOut(
    User $user,
    Account $bank,
    ImportRun $run,
    int $absMinor,
    string $counterpartyIban,
    string $postedAt,
    int $rowIndex = 1,
): Transaction {
    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $bank->id,
        'type' => 'transfer_out',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => -$absMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$absMinor,
        'settled_currency' => 'EUR',
        'counterparty_iban' => $counterpartyIban,
        'counterparty_name' => 'Bulk Settlement',
        'counterparty_normalized' => 'bulk-settlement',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 9000 + $rowIndex,
        'fingerprint' => str_pad('atc'.$rowIndex, 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'ics-alias',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->bank = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN bank',
        'slug' => 'ics-alias-bank',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->icsCard = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS card',
        'slug' => 'ics-alias-card',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $this->paypal = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'PayPal',
        'slug' => 'ics-alias-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);

    // Seed the canonical alias rows (LU…E → paypal, NL08ABNA… → ics_card).
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($this->user);

    $this->icsRun = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/ics-alias.pdf',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $this->asnRun = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ics-alias-asn.csv',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $this->resolver = $resolver;
});

it('end-to-end: ICS card_statement (from upserter) + ASN transfer_out → resolver writes ics_bulk_settle chain_links via the alias bridge', function (): void {
    // Promote the statement_summary through the production upserter so the
    // fixture matches what the real ConfirmImport boundary produces.
    $statementTotalAbs = 10000;
    StatementSummary::query()->create([
        'user_id' => $this->user->id,
        'import_run_id' => $this->icsRun->id,
        'account_id' => $this->icsCard->id,
        'iban_owner' => 'ICS-CARD',
        'statement_number' => '2026-04',
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'opening_balance_minor' => 0,
        'opening_balance_currency' => 'EUR',
        'closing_balance_minor' => -$statementTotalAbs,
        'closing_balance_currency' => 'EUR',
        'entry_count' => 3,
    ]);
    /** @var UpsertsCardStatements $upserter */
    $upserter = $this->app->make(UpsertsCardStatements::class);
    // Wipe any auto-applied back-population rows so the test owns table state.
    CardStatement::query()->where('user_id', $this->user->id)->delete();
    $inserted = $upserter->upsertForImportRun($this->icsRun->id, $this->user);
    expect($inserted)->toBe(1);

    // Three ICS expenses inside the period, summing to the statement total exactly.
    $expense1 = aliasIcsExpense($this->user, $this->icsCard, $this->icsRun, 4000, '2026-04-05', 1);
    $expense2 = aliasIcsExpense($this->user, $this->icsCard, $this->icsRun, 3500, '2026-04-12', 2);
    $expense3 = aliasIcsExpense($this->user, $this->icsCard, $this->icsRun, 2500, '2026-04-22', 3);

    // The ASN counterparty IBAN alias-resolves to ics_card, and posted_at sits
    // within the resolver's ±10-day window around period_end (2026-04-30).
    $transferOut = aliasAsnTransferOut(
        $this->user,
        $this->bank,
        $this->asnRun,
        $statementTotalAbs,
        'NL08ABNA0526650664',
        '2026-05-02',
    );

    $this->resolver->resolveForUser($this->user);

    $links = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->where('kind', 'ics_bulk_settle')
        ->where('state', 'confirmed')
        ->get();
    expect($links->count())->toBeGreaterThan(0);

    $fromIds = $links->pluck('from_transaction_id')->unique()->values();
    expect($fromIds->toArray())->toBe([$transferOut->id]);

    $toIds = $links->pluck('to_transaction_id')->filter()->values()->all();
    expect($toIds)->toContain($expense1->id);
    expect($toIds)->toContain($expense2->id);
    expect($toIds)->toContain($expense3->id);
});

it('ASN transfer_out whose counterparty does NOT alias-resolve to ics_card is skipped', function (): void {
    // Seed an open ICS card_statement so the resolver could in theory
    // match against it — the only blocker is the alias-resolve test.
    CardStatement::query()->where('user_id', $this->user->id)->delete();
    CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsCard->id,
        'import_run_id' => $this->icsRun->id,
        'period_start' => '2026-04-01 00:00:00',
        'period_end' => '2026-04-30 23:59:59',
        'total_amount_minor' => -10000,
        'open_balance_minor' => 10000,
        'state' => 'open',
    ]);
    aliasIcsExpense($this->user, $this->icsCard, $this->icsRun, 10000, '2026-04-15', 1);

    // This counterparty IBAN alias-resolves to a paypal-kind account, not
    // ics_card, so the resolver must skip the row.
    aliasAsnTransferOut(
        $this->user,
        $this->bank,
        $this->asnRun,
        10000,
        'LU89751000135104200E',
        '2026-05-02',
    );

    $this->resolver->resolveForUser($this->user);

    expect(
        ChainLink::query()
            ->where('user_id', $this->user->id)
            ->where('kind', 'ics_bulk_settle')
            ->count(),
    )->toBe(0);
});

it('ASN transfer_out with no matching open card_statement is a no-op (half-state)', function (): void {
    // Persist NO card_statements rows — the upserter never ran.
    CardStatement::query()->where('user_id', $this->user->id)->delete();
    aliasIcsExpense($this->user, $this->icsCard, $this->icsRun, 5000, '2026-04-15', 1);
    aliasAsnTransferOut(
        $this->user,
        $this->bank,
        $this->asnRun,
        5000,
        'NL08ABNA0526650664',
        '2026-05-02',
    );

    $this->resolver->resolveForUser($this->user);

    expect(
        ChainLink::query()
            ->where('user_id', $this->user->id)
            ->where('kind', 'ics_bulk_settle')
            ->count(),
    )->toBe(0);
});
