<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

// A transaction's amount lives in four columns plus the rate relating them.
// The auto-applied prefer_receipt arm wrote only the native leg, so the row
// hashed 31.99 into its own dedup key while every balance, budget and forecast
// went on summing the settled 25.00 beside it.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-11 09:00:00'));

    $seeded = $this->seedFixtureUserAndAccount();
    $this->account = $seeded['account'];

    DB::table('users')->where('id', $this->fixtureUser->id)->update(['receipt_conflict_resolution' => 'prefer_receipt']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function wholeAmountRun(User $user): ImportRun
{
    return ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/whole-amount-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'whole-amount-'.uniqid('', true)),
        'uploaded_at' => CarbonImmutable::parse('2026-03-10 09:00:00'),
        'status' => 'confirmed',
    ]);
}

/**
 * @param  array<string, mixed>  $amountColumns
 */
function wholeAmountTransaction(User $user, int $accountId, array $amountColumns): Transaction
{
    return Transaction::create(array_merge([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'import_run_id' => wholeAmountRun($user)->id,
        'type' => 'expense',
        'posted_at' => '2026-03-10',
        'booked_at' => '2026-03-10 12:00:00',
        'value_date' => '2026-03-10',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'paypal-csv',
        'source_row_index' => 0,
        'source_ref' => 'CSV-REF',
        'fingerprint' => hash('sha256', 'whole-amount-tx-'.uniqid('', true)),
        'fingerprint_version' => 1,
    ], $amountColumns));
}

/**
 * @param  array<string, array{stored: mixed, incoming: mixed}>  $conflictingFields
 */
function resolveOnto(User $user, int $transactionId, array $conflictingFields): void
{
    /** @var AppliesEnrichments $applier */
    $applier = app(AppliesEnrichments::class);

    $applier([
        new PendingEnrichment(
            existingTransactionId: $transactionId,
            newSourceRef: 'RECEIPT-'.bin2hex(random_bytes(3)),
            importRunId: 1,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: $conflictingFields,
        ),
    ], $user);
}

it('carries the settled leg with the native one when the receipt names a different amount', function (): void {
    $tx = wholeAmountTransaction($this->fixtureUser, $this->account->id, [
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
    ]);

    resolveOnto($this->fixtureUser, $tx->id, ['amount_minor' => ['stored' => -2500, 'incoming' => -3199]]);

    $row = DB::table('transactions')->where('id', $tx->id)->first();
    expect((int) $row->amount_minor)->toBe(-3199);
    expect((int) $row->settled_amount_minor)->toBe(-3199);
    expect((string) $row->settled_currency)->toBe('EUR');
    expect($row->fx_rate_used)->toBeNull();
});

it('carries the settled currency with the native one, leaving no rate to relate a row to itself', function (): void {
    $tx = wholeAmountTransaction($this->fixtureUser, $this->account->id, [
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
    ]);

    resolveOnto($this->fixtureUser, $tx->id, ['currency' => ['stored' => 'EUR', 'incoming' => 'USD']]);

    $row = DB::table('transactions')->where('id', $tx->id)->first();
    expect((string) $row->currency)->toBe('USD');
    expect((string) $row->settled_currency)->toBe('USD');
    expect((int) $row->settled_amount_minor)->toBe(-2500);
    expect($row->fx_rate_used)->toBeNull();
});

// A receipt names what the reader paid, which is the native leg. The bank's
// own conversion is a second fact no receipt restates, so it stands — and the
// rate stops describing the pair unless it is re-derived beside it.
it('leaves the bank conversion standing on a cross-currency row and re-derives the rate', function (): void {
    $tx = wholeAmountTransaction($this->fixtureUser, $this->account->id, [
        'amount_minor' => -3000,
        'currency' => 'USD',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'fx_rate_used' => '0.83333333',
    ]);

    resolveOnto($this->fixtureUser, $tx->id, ['amount_minor' => ['stored' => -3000, 'incoming' => -4000]]);

    $row = DB::table('transactions')->where('id', $tx->id)->first();
    expect((int) $row->amount_minor)->toBe(-4000);
    expect((int) $row->settled_amount_minor)->toBe(-2500);
    expect((string) $row->settled_currency)->toBe('EUR');
    expect((float) $row->fx_rate_used)->toBe(0.625);
});

it('leaves every amount column alone when the conflict is only about a name', function (): void {
    $tx = wholeAmountTransaction($this->fixtureUser, $this->account->id, [
        'amount_minor' => -3000,
        'currency' => 'USD',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'fx_rate_used' => '0.83333333',
    ]);

    resolveOnto($this->fixtureUser, $tx->id, [
        'counterparty_name' => ['stored' => 'Albert Heijn', 'incoming' => 'Albert Heijn BV'],
    ]);

    $row = DB::table('transactions')->where('id', $tx->id)->first();
    expect((int) $row->amount_minor)->toBe(-3000);
    expect((int) $row->settled_amount_minor)->toBe(-2500);
    expect((string) $row->currency)->toBe('USD');
    expect((string) $row->settled_currency)->toBe('EUR');
    expect((float) $row->fx_rate_used)->toBe(0.83333333);
});
