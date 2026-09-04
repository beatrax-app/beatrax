<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

// The action has three ways to clear a conflict without writing the reader's
// answer: the transaction is reconciled, the recomposed row already exists, or
// the stored value will not decode. The first two say so. The third destroyed
// the only copy of the answer and said nothing, so "resolved: 1" was the whole
// account of a receipt value that never landed.

function conflictGateSpyLogger(): object
{
    return new class extends AbstractLogger
    {
        /** @var list<array{message: string, context: array<mixed>}> */
        public array $records = [];

        /**
         * @param  mixed  $level
         * @param  Stringable|string  $message
         * @param  array<mixed>  $context
         */
        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['message' => (string) $message, 'context' => $context];
        }

        /** @return list<array{message: string, context: array<mixed>}> */
        public function clearedWithoutAWrite(): array
        {
            return array_values(array_filter(
                $this->records,
                static fn (array $r): bool => str_contains($r['message'], 'cleared without a write'),
            ));
        }
    };
}

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureAccount = $seeded['account'];
    $this->actingAs($this->fixtureUser);

    $fingerprints = $this->app->make(FingerprintComposer::class);
    $normalized = $this->app->make(CounterpartyKey::class)->forName('Stored Merchant', $this->fixtureUser->id);

    $run = ImportRun::create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/undecodable-conflict.dat',
        'sha256' => str_pad('1', 64, 'd', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $this->fixtureTransaction = Transaction::create([
        'user_id' => $this->fixtureUser->id,
        'account_id' => $this->fixtureAccount->id,
        'type' => 'expense',
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 12:00:00',
        'value_date' => '2026-04-01',
        'amount_minor' => -3199,
        'currency' => 'EUR',
        'settled_amount_minor' => -3199,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Stored Merchant',
        'counterparty_normalized' => $normalized,
        'normalization_version' => $fingerprints->version(),
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => $fingerprints->composeTuple(
            $this->fixtureUser->id,
            $this->fixtureAccount->id,
            '2026-04-01',
            '2026-04-01 12:00:00',
            -3199,
            'EUR',
            $normalized,
        ),
        'fingerprint_version' => $fingerprints->version(),
    ]);

    // What a half-written or truncated enrichment leaves behind: the column is
    // NOT NULL, so the row exists and only its payload is unreadable.
    $this->undecodableConflictId = (int) DB::table('pending_enrichment_conflicts')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'transaction_id' => $this->fixtureTransaction->id,
        'field_name' => 'amount_minor',
        'stored_value' => json_encode(-3199),
        'incoming_value' => '{"amount_minor": -42',
        'incoming_source_format' => 'paypal-receipt',
        'import_run_id' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
});

it('names the gate when a conflict clears because its stored answer would not decode', function (): void {
    $spy = conflictGateSpyLogger();
    $this->app->instance(LoggerInterface::class, $spy);

    $resolved = $this->app->make(ApplyReceiptConflictResolution::class)(
        $this->fixtureUser,
        ReceiptConflictChoice::PreferReceipt,
        $this->undecodableConflictId,
    );

    $records = $spy->clearedWithoutAWrite();

    expect($records)->toHaveCount(1)
        ->and($records[0]['context'])->toBe([
            'transaction_id' => (int) $this->fixtureTransaction->id,
            'field' => 'amount_minor',
        ]);

    // The count and the delete are both unchanged: the conflict IS cleared, and
    // the reader must not be handed a toast they can never be rid of.
    expect($resolved)->toBe(1)
        ->and(DB::table('pending_enrichment_conflicts')->where('id', $this->undecodableConflictId)->exists())->toBeFalse();
});

it('leaves the stored amount alone rather than writing a value it could not read', function (): void {
    $this->app->make(ApplyReceiptConflictResolution::class)(
        $this->fixtureUser,
        ReceiptConflictChoice::PreferReceipt,
        $this->undecodableConflictId,
    );

    expect((int) $this->fixtureTransaction->fresh()->amount_minor)->toBe(-3199);
});
