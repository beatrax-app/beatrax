<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureAccount = $seeded['account'];

    DB::table('users')->where('id', $this->fixtureUser->id)
        ->update(['receipt_conflict_resolution' => 'prefer_receipt']);

    $run = ImportRun::create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/enriched-index.csv',
        'sha256' => str_pad('1', 64, 'e', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $this->transaction = Transaction::create([
        'user_id' => $this->fixtureUser->id,
        'account_id' => $this->fixtureAccount->id,
        'type' => 'expense',
        'posted_at' => '2026-03-10',
        'booked_at' => '2026-03-10 12:00:00',
        'value_date' => '2026-03-10',
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'NLPAYPAL 4RTX9',
        'counterparty_normalized' => 'nlpaypal 4rtx9',
        'normalization_version' => 1,
        'description' => 'Card payment',
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'source_ref' => 'CSV-REF',
        'fingerprint' => str_pad('1', 64, 'f', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    app(SearchIndexWriterContract::class)
        ->upsertForTransaction($this->transaction->id, $this->fixtureUser->id);
});

it('indexes the counterparty name a receipt enrichment wrote', function (): void {
    app(AppliesEnrichments::class)([
        new PendingEnrichment(
            existingTransactionId: $this->transaction->id,
            newSourceRef: 'RECEIPT-77',
            importRunId: $this->transaction->import_run_id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL 4RTX9', 'incoming' => 'Albert Heijn'],
            ],
        ),
    ], $this->fixtureUser);

    $body = DB::table('transaction_search_docs')
        ->where('transaction_id', $this->transaction->id)
        ->value('search_body');

    expect(DB::table('transactions')->where('id', $this->transaction->id)->value('counterparty_name'))
        ->toBe('Albert Heijn')
        ->and($body)->toContain('Albert Heijn');
});
