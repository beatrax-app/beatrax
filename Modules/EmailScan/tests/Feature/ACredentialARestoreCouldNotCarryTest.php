<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\DatabaseRestored;
use Modules\EmailScan\Models\Inbox;
use Modules\EmailScan\Models\OAuthSecret;
use Modules\EmailScan\Public\Enums\InboxScanStatus;

// The stored client secret and token blob are encrypted under the application
// key, which lives in configuration beside the database rather than inside it —
// so it travels in no backup. A restore therefore lands rows no key on this
// install opens: consent at the provider is untouched, and the local copy of it
// is bytes nobody can read. Nothing looked, so the reader met a decryption
// failure at whatever hour the next scan ran.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'mail-restore-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

// Written as another install's ciphertext would arrive: a well-formed payload,
// in the very shape the cast writes, under a key this one does not hold. A
// random string would fail for the wrong reason and prove the branch below only
// by accident — and so would a payload serialized the other way round.
function restoreLandsACredentialFromAnotherInstall(int $userId, string $provider = 'gmail'): void
{
    $foreign = app(Encrypter::class);
    $otherInstall = new Illuminate\Encryption\Encrypter(random_bytes(32), 'aes-256-cbc');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('oauth_secrets')->insert([
        'user_id' => $userId,
        'provider' => $provider,
        'client_id' => 'a-client-id',
        'client_secret' => $otherInstall->encryptString('the-secret'),
        'redirect_uri' => 'http://127.0.0.1/callback',
        'tokens_blob' => $otherInstall->encryptString('{"refresh_token":"x"}'),
        'created_at' => '2026-09-12 10:00:00',
        'updated_at' => '2026-09-12 10:00:00',
    ]);

    expect($foreign)->not->toBeNull();
}

it('clears a credential this install cannot read and asks for a reconnect', function (): void {
    /** @var User $user */
    $user = $this->user;

    $inbox = Inbox::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'reader@example.com',
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('inbox_scan_state')->insert([
        'inbox_id' => $inbox->id,
        'folder' => 'INBOX',
        'status' => InboxScanStatus::Idle->value,
        'created_at' => '2026-09-12 10:00:00',
        'updated_at' => '2026-09-12 10:00:00',
    ]);

    restoreLandsACredentialFromAnotherInstall((int) $user->id);

    expect(OAuthSecret::query()->count())->toBe(1);

    event(new DatabaseRestored('/tmp/pre-restore.sqlite'));

    $status = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inbox->id)->value('status');

    expect(OAuthSecret::query()->count())->toBe(0, 'A row nothing can read still reads as a live connection to every surface that asks whether one exists.')
        ->and($status)->toBe(InboxScanStatus::NeedsReauth->value);
});

// The positive control. A credential this install CAN read is a credential a
// restore did not strand, and clearing it would disconnect a working mailbox.
it('leaves a credential this install can read exactly where it is', function (): void {
    /** @var User $user */
    $user = $this->user;

    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'a-client-id',
        'client_secret' => 'the-secret',
        'redirect_uri' => 'http://127.0.0.1/callback',
        'tokens_blob' => '{"refresh_token":"x"}',
    ]);

    event(new DatabaseRestored('/tmp/pre-restore.sqlite'));

    $secret = OAuthSecret::query()->first();

    expect($secret)->not->toBeNull()
        ->and($secret->client_secret)->toBe('the-secret');
});

// One mailbox answers for itself. A reset that throws must not leave the rows
// after it holding credentials the reader is never prompted to replace.
it('clears the second account when the first one throws', function (): void {
    /** @var User $user */
    $user = $this->user;

    $other = User::query()->create([
        'username' => 'mail-restore-second',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    // An inbox with no scan-state row: applyStatus throws for it, and this
    // account is inserted first so the throw lands before the other one.
    $orphan = Inbox::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'first@example.com',
    ]);

    restoreLandsACredentialFromAnotherInstall((int) $user->id);
    restoreLandsACredentialFromAnotherInstall((int) $other->id);

    expect(OAuthSecret::query()->count())->toBe(2)
        ->and($orphan)->not->toBeNull();

    event(new DatabaseRestored('/tmp/pre-restore.sqlite'));

    expect(OAuthSecret::query()->where('user_id', $other->id)->count())->toBe(0);
});

// The rows are in by the time the repair runs. A restore that reported failure
// afterwards would tell the reader nothing had been changed about a database
// that had just been replaced — so a repair that throws is reported, not fatal.
it('does not turn a repair that cannot run into a restore that failed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->getSchemaBuilder()->drop('oauth_secrets');

    expect(fn () => event(new DatabaseRestored('/tmp/pre-restore.sqlite')))->not->toThrow(Throwable::class);
});
