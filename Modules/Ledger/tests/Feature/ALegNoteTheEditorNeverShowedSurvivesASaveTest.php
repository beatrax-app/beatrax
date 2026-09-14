<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// The split editor loads every leg's note, decrypted, into the form it posts
// back. A leg whose note no epoch here opened loads as an empty box, so a save
// that touched only another leg's AMOUNT posts null for it -- and updateLeg()
// writes what it is posted. Nothing about that is the reader acting on the note.

function lgnSession(): Session
{
    /** @var Session $session */
    $session = app(Session::class);

    return $session;
}

function lgnUser(): User
{
    $user = User::query()->create([
        'username' => 'leg-note-survives',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    AppLockTestHarness::unlock(lgnSession(), str_repeat("\x2a", 32));
    app(GdkKeyringService::class)->generateAndPersist((int) $user->id, lgnSession());

    return $user;
}

function lgnCategory(string $name): int
{
    return (int) DB::table('categories')->insertGetId([
        'user_id' => null, 'parent_id' => null, 'name' => $name,
        'slug' => strtolower($name), 'kind' => 'expense', 'display_order' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function lgnTransaction(User $user, int $minor): int
{
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'lgn-asn', 'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/lgn.csv',
        'sha256' => hash('sha256', 'lgn'), 'uploaded_at' => '2026-09-01 00:00:00', 'status' => 'imported',
        'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
    ]);

    return (int) DB::table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'type' => 'expense',
        'posted_at' => '2026-09-01', 'booked_at' => '2026-09-01 00:00:00', 'value_date' => '2026-09-01',
        'amount_minor' => $minor, 'currency' => 'EUR',
        'settled_amount_minor' => $minor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'lgnfixture', 'normalization_version' => 1,
        'occurrence_ordinal' => 0, 'fingerprint' => 'lgn-fixture', 'fingerprint_version' => 1,
        'source_format' => 'asn_csv', 'import_run_id' => $runId, 'source_row_index' => 1,
        'status' => 'booked', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function lgnLeg(User $user, int $transactionId, int $categoryId, int $minor, ?string $storedNote, int $order): int
{
    return (int) DB::table('transaction_splits')->insertGetId([
        'user_id' => $user->id, 'transaction_id' => $transactionId, 'category_id' => $categoryId,
        'settled_amount_minor' => $minor, 'settled_currency' => 'EUR', 'note' => $storedNote,
        'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('leaves a leg note it could not open where it is when another leg is re-balanced', function (): void {
    $user = lgnUser();
    $transactionId = lgnTransaction($user, -3000);
    $groceries = lgnCategory('Groceries');
    $household = lgnCategory('Household');

    $foreign = base64_encode(random_bytes(48));
    $legA = lgnLeg($user, $transactionId, $groceries, -1000, null, 0);
    $legB = lgnLeg($user, $transactionId, $household, -2000, $foreign, 1);

    // What the editor posts after the reader re-balanced the amounts and never
    // touched a note: leg B's note came back as the empty box it was shown.
    app(SaveTransactionSplit::class)->save($user, $transactionId, [
        ['id' => $legA, 'category_id' => $groceries, 'settled_amount_minor' => -1500, 'note' => null],
        ['id' => $legB, 'category_id' => $household, 'settled_amount_minor' => -1500, 'note' => null],
    ]);

    expect(DB::table('transaction_splits')->where('id', $legB)->value('settled_amount_minor'))
        ->toBe(-1500, 'the re-balance did not land, so the case below is not the one this is about')
        ->and(DB::table('transaction_splits')->where('id', $legB)->value('note'))
        ->toBe($foreign, 'the leg note the editor never showed was written over with null');
});

// The other half, in both directions. A rule that kept the stored bytes
// whenever the box came back empty would satisfy the case above while making a
// note impossible to clear, and one that never kept them would pass neither.
it('still clears a leg note the reader could read and emptied', function (): void {
    $user = lgnUser();
    $transactionId = lgnTransaction($user, -3000);
    $groceries = lgnCategory('Groceries');
    $codec = app(SensitiveColumnCodec::class);

    $legA = lgnLeg($user, $transactionId, $groceries, -1000, null, 0);
    $legB = lgnLeg($user, $transactionId, $groceries, -2000, $codec->encryptValue(
        'transaction_splits', 'note', 'split with Ramona', (int) $user->id, lgnSession(),
    ), 1);

    app(SaveTransactionSplit::class)->save($user, $transactionId, [
        ['id' => $legA, 'category_id' => $groceries, 'settled_amount_minor' => -1500, 'note' => null],
        ['id' => $legB, 'category_id' => $groceries, 'settled_amount_minor' => -1500, 'note' => null],
    ]);

    expect(DB::table('transaction_splits')->where('id', $legB)->value('note'))->toBeNull();
});

it('still writes a leg note the reader typed', function (): void {
    $user = lgnUser();
    $transactionId = lgnTransaction($user, -3000);
    $groceries = lgnCategory('Groceries');

    $foreign = base64_encode(random_bytes(48));
    $legA = lgnLeg($user, $transactionId, $groceries, -1000, null, 0);
    $legB = lgnLeg($user, $transactionId, $groceries, -2000, $foreign, 1);

    app(SaveTransactionSplit::class)->save($user, $transactionId, [
        ['id' => $legA, 'category_id' => $groceries, 'settled_amount_minor' => -1500, 'note' => null],
        ['id' => $legB, 'category_id' => $groceries, 'settled_amount_minor' => -1500, 'note' => 'typed over it deliberately'],
    ]);

    $stored = (string) DB::table('transaction_splits')->where('id', $legB)->value('note');

    expect($stored)->not->toBe($foreign)
        ->and(app(SensitiveColumnCodec::class)
            ->decryptValue('transaction_splits', 'note', $stored, (int) $user->id, lgnSession())['value'])
        ->toBe('typed over it deliberately');
});
