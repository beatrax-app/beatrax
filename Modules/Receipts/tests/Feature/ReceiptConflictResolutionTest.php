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
use Modules\Receipts\Public\Enums\ReceiptConflictChoice;

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
        // The unique index spans booked_at, so two fixtures in one test have
        // to differ there or the second insert never lands.
        'booked_at' => '2026-04-01 12:00:'.str_pad((string) $idx, 2, '0', STR_PAD_LEFT),
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

it('takes the choice as a ReceiptConflictChoice, so no unrepresentable value can reach it', function (): void {
    $choice = (new ReflectionMethod(ApplyReceiptConflictResolution::class, '__invoke'))->getParameters()[1];

    expect((string) $choice->getType())->toBe(ReceiptConflictChoice::class);
    expect($choice->getType()?->allowsNull())->toBeFalse();
});

it('rejects the stored unset sentinel at the type, never as a runtime string', function (): void {
    // 'unset' is the users.receipt_conflict_resolution default and not a
    // resolution: the enum has no case for it, so the value the old
    // string signature had to reject at runtime cannot be constructed.
    expect(ReceiptConflictChoice::tryFrom('unset'))->toBeNull();
    expect(ReceiptConflictChoice::tryFrom(''))->toBeNull();
    expect(array_column(ReceiptConflictChoice::cases(), 'value'))
        ->toBe(['prefer_receipt', 'prefer_first_write']);
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
    $count = $resolve($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $seeded['conflict_id']);

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
    $count = $resolve($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $seeded['conflict_id']);

    expect($count)->toBe(1);

    // Transaction was updated.
    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('Albert Heijn');

    // Pending row was deleted.
    expect(DB::table('pending_enrichment_conflicts')->where('id', $seeded['conflict_id'])->count())->toBe(0);

    // User setting persisted.
    $userRow = DB::table('users')->where('id', $this->fixtureUser->id)->first(['receipt_conflict_resolution']);
    expect($userRow->receipt_conflict_resolution)->toBe(ReceiptConflictChoice::PreferReceipt->value);
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
    $count = $resolve($this->fixtureUser, ReceiptConflictChoice::PreferFirstWrite, $seeded['conflict_id']);

    expect($count)->toBe(1);

    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('NLPAYPAL ALBERT HEIJN'); // unchanged

    expect(DB::table('pending_enrichment_conflicts')->where('id', $seeded['conflict_id'])->count())->toBe(0);

    $userRow = DB::table('users')->where('id', $this->fixtureUser->id)->first(['receipt_conflict_resolution']);
    expect($userRow->receipt_conflict_resolution)->toBe(ReceiptConflictChoice::PreferFirstWrite->value);
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

    // Naming the foreign conflict id outright resolves nothing: the user_id
    // predicate, not the id, is what scopes the read.
    expect($resolve($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $foreign['conflict_id']))->toBe(0);

    $count = $resolve($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $own['conflict_id']);

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

// The toast names ONE conflict and quotes its two values. Whatever the button
// does has to be that one conflict: a second outstanding conflict the reader was
// never shown must survive the press, or they consented to one change and got
// every change.
it('SFC useReceipt action resolves the held conflict and leaves an unshown one outstanding', function (): void {
    $unshown = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'UNSHOWN STORED',
        incoming: 'UNSHOWN INCOMING',
    );
    $held = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'NLPAYPAL ALBERT HEIJN',
        incoming: 'Albert Heijn',
    );

    Livewire::test(ReceiptConflictToast::class)
        ->assertSet('receiptValue', 'Albert Heijn')
        ->call('useReceipt');

    $row = DB::table('transactions')->where('id', $held['tx']->id)->first();
    expect($row->counterparty_name)->toBe('Albert Heijn');
    expect(DB::table('pending_enrichment_conflicts')->where('id', $held['conflict_id'])->count())->toBe(0);
    expect(DB::table('users')->where('id', $this->fixtureUser->id)->first()->receipt_conflict_resolution)->toBe(ReceiptConflictChoice::PreferReceipt->value);

    $untouched = DB::table('transactions')->where('id', $unshown['tx']->id)->first();
    expect($untouched->counterparty_name)->toBe('UNSHOWN STORED');
    expect(DB::table('pending_enrichment_conflicts')->where('id', $unshown['conflict_id'])->count())->toBe(1);
});

// One press, one conflict: the toast then offers the next one rather than
// vanishing with outstanding conflicts behind it.
it('SFC offers the next outstanding conflict after the held one is resolved', function (): void {
    seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'NEXT STORED',
        incoming: 'NEXT INCOMING',
    );
    seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'HELD STORED',
        incoming: 'HELD INCOMING',
    );

    Livewire::test(ReceiptConflictToast::class)
        ->call('useReceipt')
        ->assertSet('visible', true)
        ->assertSet('receiptValue', 'NEXT INCOMING')
        ->call('useReceipt')
        ->assertSet('visible', false);

    expect(DB::table('pending_enrichment_conflicts')->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
});

it('SFC keepStatement action clears only the held conflict and dismisses the toast', function (): void {
    $unshown = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'UNSHOWN STORED',
        incoming: 'UNSHOWN INCOMING',
    );
    $held = seedTxAndPendingConflict(
        $this->fixtureUser,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'NLPAYPAL ALBERT HEIJN',
        incoming: 'Albert Heijn',
    );

    Livewire::test(ReceiptConflictToast::class)
        ->call('keepStatement');

    $row = DB::table('transactions')->where('id', $held['tx']->id)->first();
    expect($row->counterparty_name)->toBe('NLPAYPAL ALBERT HEIJN');
    expect(DB::table('pending_enrichment_conflicts')->where('id', $held['conflict_id'])->count())->toBe(0);
    expect(DB::table('users')->where('id', $this->fixtureUser->id)->first()->receipt_conflict_resolution)->toBe(ReceiptConflictChoice::PreferFirstWrite->value);

    expect(DB::table('pending_enrichment_conflicts')->where('id', $unshown['conflict_id'])->count())->toBe(1);
});

it('Blade view has NO auto-dismiss (UI-SPEC: persists until user acts)', function (): void {
    $viewPath = __DIR__.'/../../Resources/views/livewire/receipt-conflict-toast.blade.php';
    $blade = (string) file_get_contents($viewPath);

    expect($blade)->not->toContain('setTimeout');
    expect($blade)->not->toContain('data-auto-dismiss');
    expect($blade)->not->toContain('x-init=');
});

// The removed twin of this drove a `receipt-conflict-detected` listener that
// nothing dispatched. What actually guards a foreign user's conflict is the
// mount read, so that is what is asserted here.
it('shows no conflict on mount when the only pending row belongs to another user', function (): void {
    $foreign = User::create([
        'username' => 'foreign-rcr',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    seedTxAndPendingConflict(
        $foreign,
        $this->fixtureAccount,
        field: 'counterparty_name',
        stored: 'should not render',
        incoming: 'should not render either',
    );

    Livewire::test(ReceiptConflictToast::class)
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
    $count = $resolve($this->fixtureUser, ReceiptConflictChoice::PreferReceipt, $seeded['conflict_id']);

    expect($count)->toBe(1);

    // The malformed value was NOT applied to the transaction…
    $row = DB::table('transactions')->where('id', $seeded['tx']->id)->first();
    expect($row->counterparty_name)->toBe('OWN STORED');

    // …and the pending row was still deleted (corrupted rows must not block
    // future conflicts).
    expect(DB::table('pending_enrichment_conflicts')->where('id', $seeded['conflict_id'])->count())->toBe(0);
});
