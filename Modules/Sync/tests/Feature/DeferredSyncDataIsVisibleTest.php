<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Internal\OpLog\SyncBacklogState;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;
use Modules\Sync\Public\Services\EncryptionRecoveryMarkers;

uses(RefreshDatabase::class);

// A desktop synced to while it was closed holds the data in its op log and
// shows none of it until the next request. Nothing is lost, but from the
// reader's side an out-of-date screen and a broken sync look identical.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#what-the-reader-is-told
 */
function backlogUser(): User
{
    return User::query()->create([
        'username' => 'backlog-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function backlogEnrol(User $user): Session
{
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(EncryptionMigrationService::class)->migrate($user, $session);

    return $session;
}

function backlogQuarantine(User $user, ?int $epoch): void
{
    app(DatabaseManager::class)->connection()->table('op_log_quarantine')->insert([
        'user_id' => $user->id,
        'table_name' => 'transactions',
        'pk' => '1',
        'device_id' => 'backlog-peer',
        'reason' => 'gdk_decrypt_failed',
        'gdk_epoch' => $epoch,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => 'sealed',
        'created_at' => '2026-08-22 16:00:09',
    ]);
}

function backlogCurrentEpoch(User $user): int
{
    $value = app(DatabaseManager::class)->connection()
        ->table('sync_encryption_state')
        ->where('user_id', $user->id)
        ->value('current_epoch');

    return is_numeric($value) ? (int) $value : 0;
}

it('says nothing when there is nothing waiting', function (): void {
    $user = backlogUser();
    backlogEnrol($user);

    Livewire::actingAs($user)->test(DevicesAndSyncSettingsSection::class)
        ->assertSet('syncBacklog', SyncBacklogState::None->value)
        ->assertDontSee('sync-backlog-notice');
});

it('tells the reader data is waiting when this device can decode it', function (): void {
    $user = backlogUser();
    backlogEnrol($user);
    backlogQuarantine($user, backlogCurrentEpoch($user));

    Livewire::actingAs($user)->test(DevicesAndSyncSettingsSection::class)
        ->assertSet('syncBacklog', SyncBacklogState::Deferred->value)
        ->assertSee('has not added it to your ledger yet');
});

// The half that must not borrow the other's words: no amount of unlocking or
// waiting produces a key this device was never sent.
it('does not promise a wait will end when the key is the thing that is missing', function (): void {
    $user = backlogUser();
    backlogEnrol($user);
    backlogQuarantine($user, backlogCurrentEpoch($user) + 1);

    Livewire::actingAs($user)->test(DevicesAndSyncSettingsSection::class)
        ->assertSet('syncBacklog', SyncBacklogState::AwaitingKey->value)
        ->assertSee('does not have the key for yet')
        ->assertDontSee('has not added it to your ledger yet');
});

it('keeps the two states apart in every locale', function (): void {
    $locales = array_map('basename', glob(base_path('Modules/Sync/Resources/lang/*'), GLOB_ONLYDIR) ?: []);
    expect(count($locales))->toBe(26);

    foreach ($locales as $locale) {
        /** @var array<string, string> $strings */
        $strings = require base_path("Modules/Sync/Resources/lang/{$locale}/devices.php");

        expect($strings)->toHaveKeys(['backlog_heading', 'backlog_deferred', 'backlog_awaiting_key']);
        expect($strings['backlog_deferred'])->not->toBe($strings['backlog_awaiting_key']);
    }
});

// A device that never enabled encryption has no sync_encryption_state row at
// all, so every marker read goes through a null. The accessors return null
// rather than raising, and the section is silent.
it('reads its marks on a device that has no encryption state row', function (): void {
    $user = backlogUser();

    /** @var EncryptionRecoveryMarkers $markers */
    $markers = app(EncryptionRecoveryMarkers::class);

    expect($markers->isEnrolled((int) $user->id))->toBeFalse();
    expect($markers->resealedColumnsDigest((int) $user->id))->toBeNull();
    expect($markers->historyReprojectedAt((int) $user->id))->toBeNull();
    expect($markers->reprojectedKeyringFingerprint((int) $user->id))->toBeNull();

    Livewire::actingAs($user)->test(DevicesAndSyncSettingsSection::class)
        ->assertSet('syncBacklog', SyncBacklogState::None->value);
});
