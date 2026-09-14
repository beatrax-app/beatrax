<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Categorization\Public\Enums\NoteMode;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Actions\SetTransactionNote;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// A note sealed under an epoch this device lacks opens as '' -- the codec says
// so deliberately, so a screen renders nothing rather than base64. Appending to
// that '' writes the addition alone, and the note it was added to is gone.

function anaSession(): Session
{
    /** @var Session $session */
    $session = app(Session::class);

    return $session;
}

function anaUser(): User
{
    $user = User::query()->create([
        'username' => 'append-unopenable',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    AppLockTestHarness::unlock(anaSession(), str_repeat("\x2a", 32));
    app(GdkKeyringService::class)->generateAndPersist((int) $user->id, anaSession());

    return $user;
}

function anaTransaction(User $user, string $storedNote): int
{
    $accountId = DB::table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'ASN', 'slug' => 'append-asn', 'kind' => 'bank', 'iban' => 'NL57ASNB0123456789',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $runId = DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/append.csv',
        'sha256' => hash('sha256', 'append'),
        'uploaded_at' => '2026-09-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);

    return DB::table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'posted_at' => '2026-09-01',
        'booked_at' => '2026-09-01 00:00:00',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'appendfixture',
        'normalization_version' => 1,
        'value_date' => '2026-09-01',
        'source_format' => 'asn_csv',
        'import_run_id' => $runId,
        'source_row_index' => 1,
        'fingerprint_version' => 1,
        'occurrence_ordinal' => 0,
        'fingerprint' => 'append-fixture-'.$user->id,
        'status' => 'booked',
        'type' => 'expense',
        'note' => $storedNote,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('opens a foreign-epoch note as nothing, which is the state this is about', function (): void {
    $user = anaUser();
    $foreign = base64_encode(random_bytes(48));

    $opened = app(SensitiveColumnCodec::class)
        ->decryptValue('transactions', 'note', $foreign, (int) $user->id, anaSession());

    expect($opened['decrypted'])->toBeFalse()
        ->and($opened['value'])->toBe('', 'the codec did not blank it, so the case below cannot arise');
});

it('leaves a note it could not read where it is', function (): void {
    $user = anaUser();
    $foreign = base64_encode(random_bytes(48));
    $id = anaTransaction($user, $foreign);

    ($setNote = app(SetTransactionNote::class))($id, 'checked with the bank', NoteMode::Append->value, $user);

    expect(DB::table('transactions')->where('id', $id)->value('note'))
        ->toBe($foreign, 'the unreadable note was replaced by the appended text alone');
});

// The other half. Without it, an append that refused every time would pass the
// case above, and the refusal would be indistinguishable from a broken append.
it('still appends to a note it could read', function (): void {
    $user = anaUser();
    $codec = app(SensitiveColumnCodec::class);
    $sealed = $codec->encryptValue('transactions', 'note', 'paid by card', (int) $user->id, anaSession());
    $id = anaTransaction($user, $sealed);

    ($setNote = app(SetTransactionNote::class))($id, 'checked with the bank', NoteMode::Append->value, $user);

    $after = (string) DB::table('transactions')->where('id', $id)->value('note');

    expect($codec->decryptValue('transactions', 'note', $after, (int) $user->id, anaSession())['value'])
        ->toContain('paid by card')
        ->toContain('checked with the bank');
});
