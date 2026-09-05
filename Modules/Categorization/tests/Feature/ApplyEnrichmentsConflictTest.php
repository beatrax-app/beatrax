<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Import\Public\Actions\ApplyEnrichments;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Public\Events\ReceiptConflictDetected;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureAccount = $seeded['account'];
});

// Resolves AppliesEnrichments AFTER Event::fake has rebound the
// dispatcher, so the action's injected Dispatcher is the fake and
// Event::assertDispatched can observe the dispatch.
function resolveApplier(): AppliesEnrichments
{
    /** @var AppliesEnrichments $action */
    $action = app(AppliesEnrichments::class);

    return $action;
}

function seedConflictTransaction(User $user, Account $account, string $sourceFormat, ?string $sourceRef): Transaction
{
    static $idx = 0;
    $idx++;

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => $sourceFormat,
        'raw_file_path' => '/tmp/conflict-'.$idx.'.dat',
        'sha256' => str_pad((string) $idx, 64, 'c', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-03-10',
        'booked_at' => '2026-03-10 12:00:00',
        'value_date' => '2026-03-10',
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'NLPAYPAL ALBERT HEIJN',
        'counterparty_normalized' => 'nlpaypal albert heijn',
        'normalization_version' => 1,
        'source_format' => $sourceFormat,
        'import_run_id' => $run->id,
        'source_row_index' => $idx,
        'source_ref' => $sourceRef,
        'fingerprint' => str_pad((string) $idx, 64, 'p', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('PendingEnrichment default conflictingFields is an empty array', function (): void {
    $pe = new PendingEnrichment(
        existingTransactionId: 1,
        newSourceRef: 'X',
        importRunId: 1,
        sourceFormat: SourceFormat::Eml->value,
    );
    expect($pe->conflictingFields)->toBe([]);
});

it('no conflict: empty conflictingFields path proceeds with pure source_ref enrichment', function (): void {
    $tx = seedConflictTransaction($this->fixtureUser, $this->fixtureAccount, 'paypal-csv', 'OLD-REF');

    Event::fake([ReceiptConflictDetected::class]);

    $count = (resolveApplier())([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'TX-12345',
            importRunId: 99,
            sourceFormat: SourceFormat::Eml->value,
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(1);
    $row = DB::table('transactions')->where('id', $tx->id)->first();
    expect($row->source_ref)->toBe('TX-12345');
    Event::assertNotDispatched(ReceiptConflictDetected::class);
    expect(DB::table('pending_enrichment_conflicts')->count())->toBe(0);
});

it('unset policy + receipt conflict: holds in pending_enrichment_conflicts + dispatches ReceiptConflictDetected per field; SKIPS per-field write', function (): void {
    // user's receipt_conflict_resolution defaults to 'unset' (migration default).
    $tx = seedConflictTransaction($this->fixtureUser, $this->fixtureAccount, 'paypal-csv', 'CSV-REF');

    Event::fake([ReceiptConflictDetected::class]);

    $count = (resolveApplier())([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'RECEIPT-77',
            importRunId: $tx->import_run_id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'Albert Heijn'],
            ],
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(1);

    $row = DB::table('transactions')->where('id', $tx->id)->first();
    expect($row->source_ref)->toBe('RECEIPT-77');
    expect($row->counterparty_name)->toBe('NLPAYPAL ALBERT HEIJN');

    $pending = DB::table('pending_enrichment_conflicts')
        ->where('user_id', $this->fixtureUser->id)
        ->where('transaction_id', $tx->id)
        ->where('field_name', 'counterparty_name')
        ->first();
    expect($pending)->not->toBeNull();
    expect((string) $pending->incoming_source_format)->toBe(SourceFormat::Eml->value);

    Event::assertDispatched(
        ReceiptConflictDetected::class,
        fn (ReceiptConflictDetected $e): bool => $e->transactionId === $tx->id
            && $e->userId === $this->fixtureUser->id
            && $e->field === 'counterparty_name'
            && $e->incomingValue === 'Albert Heijn'
            && $e->storedValue === 'NLPAYPAL ALBERT HEIJN'
    );

    $pendingRow = DB::table('pending_enrichment_conflicts')->where('transaction_id', $tx->id)->first();
    expect($pendingRow->resolution)->toBeNull();

    $latest = app(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser);
    expect($latest)->not->toBeNull();
    expect($latest['conflictId'])->toBe((int) $pendingRow->id);
});

it('prefer_receipt policy: applies the incoming value AND records the disagreement, stamped with the policy that settled it', function (): void {
    DB::table('users')->where('id', $this->fixtureUser->id)->update([
        'receipt_conflict_resolution' => 'prefer_receipt',
    ]);
    $tx = seedConflictTransaction($this->fixtureUser, $this->fixtureAccount, 'paypal-csv', 'CSV-REF');

    Event::fake([ReceiptConflictDetected::class]);

    $count = (resolveApplier())([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'RECEIPT-77',
            importRunId: $tx->import_run_id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'Albert Heijn'],
            ],
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(1);

    $row = DB::table('transactions')->where('id', $tx->id)->first();
    expect($row->source_ref)->toBe('RECEIPT-77');
    expect($row->counterparty_name)->toBe('Albert Heijn');

    $pending = DB::table('pending_enrichment_conflicts')->where('transaction_id', $tx->id)->get();
    expect($pending)->toHaveCount(1);
    expect($pending[0]->field_name)->toBe('counterparty_name');
    expect($pending[0]->resolution)->toBe('prefer_receipt');
    expect(json_decode((string) $pending[0]->stored_value, true))->toBe('NLPAYPAL ALBERT HEIJN');
    expect(json_decode((string) $pending[0]->incoming_value, true))->toBe('Albert Heijn');

    Event::assertDispatched(ReceiptConflictDetected::class);

    // Recorded, not re-asked: the reader answered this question once and the
    // stamped row is what keeps the toast from posing it again.
    expect(app(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser))->toBeNull();
});

it('prefer_first_write policy: keeps the stored value AND records the disagreement; source_ref still updates', function (): void {
    DB::table('users')->where('id', $this->fixtureUser->id)->update([
        'receipt_conflict_resolution' => 'prefer_first_write',
    ]);
    $tx = seedConflictTransaction($this->fixtureUser, $this->fixtureAccount, 'paypal-csv', 'CSV-REF');

    Event::fake([ReceiptConflictDetected::class]);

    $count = (resolveApplier())([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'RECEIPT-77',
            importRunId: $tx->import_run_id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'Albert Heijn'],
            ],
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(1);

    $row = DB::table('transactions')->where('id', $tx->id)->first();
    expect($row->source_ref)->toBe('RECEIPT-77');
    expect($row->counterparty_name)->toBe('NLPAYPAL ALBERT HEIJN');

    $pending = DB::table('pending_enrichment_conflicts')->where('transaction_id', $tx->id)->get();
    expect($pending)->toHaveCount(1);
    expect($pending[0]->resolution)->toBe('prefer_first_write');
    expect(json_decode((string) $pending[0]->incoming_value, true))->toBe('Albert Heijn');

    Event::assertDispatched(ReceiptConflictDetected::class);
    expect(app(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser))->toBeNull();
});

it('cross-user: pending_enrichment_conflicts for another user is NEVER touched', function (): void {
    $other = User::create([
        'username' => 'other-conflict',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $otherAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'ASN-other',
        'slug' => 'asn-other-conflict',
        'kind' => 'bank',
        'iban' => 'NL43ASNB0000000000',
        'default_currency' => 'EUR',
    ]);

    // Seed a real foreign transaction + a foreign pending row that
    // FK-validates against transactions.id.
    $foreignTx = seedConflictTransaction($other, $otherAccount, 'paypal-csv', 'FOREIGN-REF');
    DB::table('pending_enrichment_conflicts')->insert([
        'user_id' => $other->id,
        'transaction_id' => $foreignTx->id,
        'field_name' => 'counterparty_name',
        'stored_value' => json_encode('foreign stored'),
        'incoming_value' => json_encode('foreign incoming'),
        'incoming_source_format' => SourceFormat::Eml->value,
        'import_run_id' => null,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    $tx = seedConflictTransaction($this->fixtureUser, $this->fixtureAccount, 'paypal-csv', 'CSV-REF');

    (resolveApplier())([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'RECEIPT-77',
            importRunId: $tx->import_run_id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'Albert Heijn'],
            ],
        ),
    ], $this->fixtureUser);

    $foreignStill = DB::table('pending_enrichment_conflicts')
        ->where('user_id', $other->id)
        ->where('transaction_id', $foreignTx->id)
        ->where('field_name', 'counterparty_name')
        ->first();
    expect($foreignStill)->not->toBeNull();
    expect(json_decode((string) $foreignStill->stored_value))->toBe('foreign stored');
    $own = DB::table('pending_enrichment_conflicts')
        ->where('user_id', $this->fixtureUser->id)
        ->where('transaction_id', $tx->id)
        ->where('field_name', 'counterparty_name')
        ->first();
    expect($own)->not->toBeNull();
});

it('unset policy + a statement enriching a receipt-written row: keeps the stored value, records the disagreement, raises no toast', function (): void {
    // A CAMT.053 (rank 4) covering a row a receipt (rank 2) wrote is the
    // default-configuration path FingerprintStage allows, and the direction
    // the toast's "prefer receipts?" question cannot be asked in.
    $tx = seedConflictTransaction($this->fixtureUser, $this->fixtureAccount, SourceFormat::Eml->value, 'RECEIPT-REF');

    Event::fake([ReceiptConflictDetected::class]);

    $count = (resolveApplier())([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'STRONGER-REF',
            importRunId: $tx->import_run_id,
            sourceFormat: SourceFormat::Camt053->value,
            conflictingFields: [
                'amount_minor' => ['stored' => -2500, 'incoming' => -9900],
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'Different name'],
            ],
        ),
    ], $this->fixtureUser);

    expect($count)->toBe(1);

    $row = DB::table('transactions')->where('id', $tx->id)->first();
    expect($row->source_ref)->toBe('STRONGER-REF');
    expect($row->counterparty_name)->toBe('NLPAYPAL ALBERT HEIJN');
    expect((int) $row->amount_minor)->toBe(-2500);

    $pending = DB::table('pending_enrichment_conflicts')
        ->where('transaction_id', $tx->id)
        ->orderBy('field_name')
        ->get();
    expect($pending)->toHaveCount(2);
    expect($pending[0]->field_name)->toBe('amount_minor');
    expect((int) json_decode((string) $pending[0]->incoming_value, true))->toBe(-9900);
    expect($pending[0]->resolution)->toBe('prefer_first_write');
    expect($pending[1]->field_name)->toBe('counterparty_name');
    expect($pending[1]->resolution)->toBe('prefer_first_write');
    expect((string) $pending[1]->incoming_source_format)->toBe(SourceFormat::Camt053->value);

    Event::assertDispatchedTimes(ReceiptConflictDetected::class, 2);
    expect(app(ReceiptConflictQuery::class)->latestForUser($this->fixtureUser))->toBeNull();
});

it('a later disagreement about the same field replaces the record rather than being the one dropped', function (): void {
    DB::table('users')->where('id', $this->fixtureUser->id)->update([
        'receipt_conflict_resolution' => 'prefer_first_write',
    ]);
    $tx = seedConflictTransaction($this->fixtureUser, $this->fixtureAccount, 'paypal-csv', 'CSV-REF');

    // A second real run and no second transaction: pending_enrichment_conflicts
    // .import_run_id is a foreign key, so the later reading needs a run to name,
    // and seeding another row would collide with the natural key this fixture
    // holds constant.
    $laterRun = ImportRun::create([
        'user_id' => $this->fixtureUser->id,
        'source_format' => SourceFormat::Eml->value,
        'raw_file_path' => '/tmp/conflict-later.eml',
        'sha256' => hash('sha256', 'conflict-later'),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $applier = resolveApplier();

    $applier([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'RECEIPT-A',
            importRunId: $tx->import_run_id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'First reading'],
            ],
        ),
    ], $this->fixtureUser);

    $applier([
        new PendingEnrichment(
            existingTransactionId: $tx->id,
            newSourceRef: 'RECEIPT-B',
            importRunId: $laterRun->id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'Second reading'],
            ],
        ),
    ], $this->fixtureUser);

    $pending = DB::table('pending_enrichment_conflicts')->where('transaction_id', $tx->id)->get();
    expect($pending)->toHaveCount(1);
    expect(json_decode((string) $pending[0]->incoming_value, true))->toBe('Second reading');
    expect((int) $pending[0]->import_run_id)->toBe((int) $laterRun->id);
});

it('W6 no-instance-cache: two consecutive __invoke calls for different users honour each user\'s policy independently (singleton safety)', function (): void {
    $userA = $this->fixtureUser; // unset
    $userB = User::create([
        'username' => 'two-user-policy',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    DB::table('users')->where('id', $userB->id)->update([
        'receipt_conflict_resolution' => 'prefer_receipt',
    ]);
    $userBAccount = Account::create([
        'user_id' => $userB->id,
        'name' => 'ASN-B',
        'slug' => 'asn-b',
        'kind' => 'bank',
        'iban' => 'NL08ASNB9999999999',
        'default_currency' => 'EUR',
    ]);

    $txA = seedConflictTransaction($userA, $this->fixtureAccount, 'paypal-csv', 'CSV-A');
    $txB = seedConflictTransaction($userB, $userBAccount, 'paypal-csv', 'CSV-B');

    // One singleton-bound action instance, two consecutive calls.
    (resolveApplier())([
        new PendingEnrichment(
            existingTransactionId: $txA->id,
            newSourceRef: 'RECEIPT-A',
            importRunId: $txA->import_run_id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'For A'],
            ],
        ),
    ], $userA);

    (resolveApplier())([
        new PendingEnrichment(
            existingTransactionId: $txB->id,
            newSourceRef: 'RECEIPT-B',
            importRunId: $txB->import_run_id,
            sourceFormat: SourceFormat::Eml->value,
            conflictingFields: [
                'counterparty_name' => ['stored' => 'NLPAYPAL ALBERT HEIJN', 'incoming' => 'For B'],
            ],
        ),
    ], $userB);

    $rowA = DB::table('transactions')->where('id', $txA->id)->first();
    expect($rowA->counterparty_name)->toBe('NLPAYPAL ALBERT HEIJN');

    $rowB = DB::table('transactions')->where('id', $txB->id)->first();
    expect($rowB->counterparty_name)->toBe('For B');
});

it('no instance-level cache property is declared on ApplyEnrichments (singleton cross-user safety check)', function (): void {
    $reflection = new ReflectionClass(ApplyEnrichments::class);
    foreach ($reflection->getProperties() as $property) {
        // A `private ?string $userPolicy` cache would leak across users on
        // the same queue worker process.
        $name = $property->getName();
        expect($name)->not->toBe('userPolicy');
        expect($name)->not->toBe('cached');
        expect($name)->not->toBe('cachedUserPolicy');
    }
});
