<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Internal\Http\Livewire\ReceiptConflictToast;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureAccount = $seeded['account'];
    $this->actingAs($this->fixtureUser);
});

function seedTxAndPendingConflict(User $user, Account $account, string $field, mixed $stored, mixed $incoming): array
{
    static $idx = 0;
    $idx++;

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/receipt-conflict-'.$idx.'.dat',
        'sha256' => str_pad((string) $idx, 64, 'r', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    $tx = Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 12:00:00',
        'value_date' => '2026-04-01',
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'counterparty_name' => is_string($stored) ? $stored : null,
        'counterparty_normalized' => 'fixture merchant',
        'normalization_version' => 1,
        'source_format' => 'paypal-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $idx,
        'fingerprint' => str_pad((string) $idx, 64, 'r', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $conflictId = (int) DB::table('pending_enrichment_conflicts')->insertGetId([
        'user_id' => $user->id,
        'transaction_id' => $tx->id,
        'field_name' => $field,
        'stored_value' => json_encode($stored),
        'incoming_value' => json_encode($incoming),
        'incoming_source_format' => 'paypal-receipt',
        'import_run_id' => $run->id,
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return ['tx' => $tx, 'conflict_id' => $conflictId];
}

it('throws InvalidArgumentException for any choice not in the whitelist', function (): void {
    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = $this->app->make(ApplyReceiptConflictResolution::class);

    expect(fn () => $resolve($this->fixtureUser, 'random_choice'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $resolve($this->fixtureUser, ''))->toThrow(InvalidArgumentException::class);
    expect(fn () => $resolve($this->fixtureUser, 'unset'))->toThrow(InvalidArgumentException::class); // unset is the DB default, not a resolution choice
});

it('rejects an out-of-whitelist field_name without mutating the transactions row (column-injection defence)', function (): void {
    // Hand-seed a pending conflict row whose `field_name` will be
    // mutated into an injection-shaped string. The transactions row
    // should remain untouched and the pending row should be deleted.
    $seeded = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'OWN STORED',
        incoming: 'OWN INCOMING',
    );

    // Replace the just-inserted pending row's `field_name` with an
    // unsafe string (any value not in the four-name whitelist).
    DB::table('pending_enrichment_conflicts')
        ->where('id', $seeded['conflict_id'])
        ->update(['field_name' => 'id = id; DROP TABLE transactions; --']);

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = $this->app->make(ApplyReceiptConflictResolution::class);
    $count = $resolve($this->fixtureUser, 'prefer_receipt');

    // The action still reports 1 conflict resolved (delete-only path).
    expect($count)->toBe(1);

    // Transactions row was NOT mutated.
    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('OWN STORED');

    // Pending row was still deleted (corrupted rows do not block future conflicts).
    expect(DB::table('pending_enrichment_conflicts')->where('id', $seeded['conflict_id'])->count())->toBe(0);
});

it('prefer_receipt: UPDATEs transactions.{field} with incoming value + DELETEs pending row + persists user setting + returns count', function (): void {
    $seeded = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'NLPAYPAL ALBERT HEIJN',
        incoming: 'Albert Heijn',
    );

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = $this->app->make(ApplyReceiptConflictResolution::class);
    $count = $resolve($this->fixtureUser, 'prefer_receipt');

    expect($count)->toBe(1);

    // Transaction was updated.
    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('Albert Heijn');

    // Pending row was deleted.
    expect(DB::table('pending_enrichment_conflicts')->where('id', $seeded['conflict_id'])->count())->toBe(0);

    // User setting persisted.
    $userRow = DB::table('users')->where('id', $this->fixtureUser->id)->first(['receipt_conflict_resolution']);
    expect($userRow->receipt_conflict_resolution)->toBe('prefer_receipt');
});

it('prefer_first_write: keeps stored value + DELETEs pending row + persists user setting + returns count', function (): void {
    $seeded = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'NLPAYPAL ALBERT HEIJN',
        incoming: 'Albert Heijn',
    );

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = $this->app->make(ApplyReceiptConflictResolution::class);
    $count = $resolve($this->fixtureUser, 'prefer_first_write');

    expect($count)->toBe(1);

    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('NLPAYPAL ALBERT HEIJN'); // unchanged

    expect(DB::table('pending_enrichment_conflicts')->where('id', $seeded['conflict_id'])->count())->toBe(0);

    $userRow = DB::table('users')->where('id', $this->fixtureUser->id)->first(['receipt_conflict_resolution']);
    expect($userRow->receipt_conflict_resolution)->toBe('prefer_first_write');
});

it('never touches a foreign user\'s pending row from ApplyReceiptConflictResolution', function (): void {
    $other = User::create([
        'username' => 'other-rcr',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $otherAccount = Account::create([
        'user_id' => $other->id,
        'name' => 'ASN-other',
        'slug' => 'asn-rcr-other',
        'kind' => 'bank',
        'iban' => 'NL43ASNB0000000000',
        'default_currency' => 'EUR',
    ]);

    $foreign = seedTxAndPendingConflict(
        $other,
        $otherAccount,
        field: 'counterparty_name',
        stored: 'FOREIGN STORED',
        incoming: 'FOREIGN INCOMING',
    );
    $own = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'OWN STORED',
        incoming: 'OWN INCOMING',
    );

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = $this->app->make(ApplyReceiptConflictResolution::class);
    $count = $resolve($this->fixtureUser, 'prefer_receipt');

    // Only the current user's conflict was resolved.
    expect($count)->toBe(1);

    // Own transaction updated.
    $ownRow = DB::table('transactions')->where('id', $own['tx']->id)->first();
    expect($ownRow->counterparty_name)->toBe('OWN INCOMING');

    // Foreign transaction untouched.
    $foreignTxRow = DB::table('transactions')->where('id', $foreign['tx']->id)->first();
    expect($foreignTxRow->counterparty_name)->toBe('FOREIGN STORED');

    // Foreign pending row still present.
    expect(DB::table('pending_enrichment_conflicts')->where('id', $foreign['conflict_id'])->count())->toBe(1);

    // Foreign user's policy unchanged.
    $foreignUserRow = DB::table('users')->where('id', $other->id)->first(['receipt_conflict_resolution']);
    expect($foreignUserRow->receipt_conflict_resolution)->toBe('unset');
});

it('SFC mounts and renders the latest pending conflict from DB', function (): void {
    seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'NLPAYPAL ALBERT HEIJN',
        incoming: 'Albert Heijn',
    );

    Livewire::test(ReceiptConflictToast::class)
        ->assertSet('visible', true)
        ->assertSet('field', 'counterparty_name')
        ->assertSet('receiptValue', 'Albert Heijn')
        ->assertSet('csvValue', 'NLPAYPAL ALBERT HEIJN')
        ->assertSee('Receipt and statement disagree.')
        ->assertSee('Use receipt')
        ->assertSee('Keep statement')
        ->assertSee('Albert Heijn');
});

it('SFC useReceipt action resolves the held conflict + dismisses the toast', function (): void {
    $seeded = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'NLPAYPAL ALBERT HEIJN',
        incoming: 'Albert Heijn',
    );

    Livewire::test(ReceiptConflictToast::class)
        ->call('useReceipt')
        ->assertSet('visible', false);

    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('Albert Heijn');
    expect(DB::table('pending_enrichment_conflicts')->where('id', $seeded['conflict_id'])->count())->toBe(0);
    expect(DB::table('users')->where('id', $this->fixtureUser->id)->first()->receipt_conflict_resolution)->toBe('prefer_receipt');
});

it('SFC keepStatement action keeps stored value + clears pending row + dismisses the toast', function (): void {
    $seeded = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'NLPAYPAL ALBERT HEIJN',
        incoming: 'Albert Heijn',
    );

    Livewire::test(ReceiptConflictToast::class)
        ->call('keepStatement')
        ->assertSet('visible', false);

    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('NLPAYPAL ALBERT HEIJN');
    expect(DB::table('pending_enrichment_conflicts')->where('id', $seeded['conflict_id'])->count())->toBe(0);
    expect(DB::table('users')->where('id', $this->fixtureUser->id)->first()->receipt_conflict_resolution)->toBe('prefer_first_write');
});

it('Blade view has NO auto-dismiss (UI-SPEC: persists until user acts)', function (): void {
    $viewPath = __DIR__.'/../../Resources/views/livewire/receipt-conflict-toast.blade.php';
    $blade = (string) file_get_contents($viewPath);

    expect($blade)->not->toContain('setTimeout');
    expect($blade)->not->toContain('data-auto-dismiss');
    expect($blade)->not->toContain('x-init=');
});

it('SFC ignores a receipt-conflict-detected event whose userId does NOT match the current user', function (): void {
    $foreign = User::create([
        'username' => 'foreign-rcr',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    Livewire::test(ReceiptConflictToast::class)
        ->dispatch('receipt-conflict-detected',
            transactionId: 999_999,
            userId: $foreign->id,
            field: 'counterparty_name',
            receiptValue: 'should not render',
            csvValue: 'should not render either',
        )
        ->assertSet('visible', false);
});

it('does not error on a malformed (non-JSON) incoming_value; skips the apply but still deletes the row', function (): void {
    $seeded = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'OWN STORED',
        incoming: 'OWN INCOMING',
    );

    // Corrupt incoming_value into a raw, non-JSON string (the shape the old
    // demo seeder produced). json_decode(..., JSON_THROW_ON_ERROR) would
    // throw on this — the action must tolerate it rather than 500.
    DB::table('pending_enrichment_conflicts')
        ->where('id', $seeded['conflict_id'])
        ->update(['incoming_value' => 'Bol.com - Order #DEMO-1234 (PayPal)']);

    /** @var ApplyReceiptConflictResolution $resolve */
    $resolve = $this->app->make(ApplyReceiptConflictResolution::class);
    $count = $resolve($this->fixtureUser, 'prefer_receipt');

    expect($count)->toBe(1);

    // The malformed value was NOT applied to the transaction…
    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('OWN STORED');

    // …and the pending row was still deleted (corrupted rows must not block
    // future conflicts).
    expect(DB::table('pending_enrichment_conflicts')->where('id', $seeded['conflict_id'])->count())->toBe(0);
});
