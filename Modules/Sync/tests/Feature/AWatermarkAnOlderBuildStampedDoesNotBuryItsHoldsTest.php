<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Internal\OpLog\SyncBacklogState;
use Modules\Sync\Public\Services\EncryptionRecoveryMarkers;
use Modules\Sync\Public\Services\HistoryReprojector;

uses(RefreshDatabase::class);

// A pass stamps a watermark saying the holds below it have had their answer.
// That is true of the build that stamped it and false of the next one: three
// fixes taught the pass to retry a create two devices minted one id for, and
// every install that ran a pass first had those holds already buried.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#telling-not-yet-openable-apart-from-never-openable-here
 */
function buriedHoldUser(): User
{
    return User::query()->create([
        'username' => 'buried-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function buriedHoldEnroll(User $user): Session
{
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(EncryptionMigrationService::class)->migrate($user, $session);

    return $session;
}

// A collision hold: the reason the three fixes taught the pass to answer, and
// the one the live install is holding 52 of. gdk_epoch is null because the
// entry it was recorded against was an unsealed field of the create.
function buriedHoldQuarantine(User $user, string $createdAt): void
{
    app(DatabaseManager::class)->connection()->table('op_log_quarantine')->insert([
        'user_id' => $user->id,
        'table_name' => 'transactions',
        'pk' => '4242',
        'device_id' => 'buried-peer',
        'reason' => 'primary_key_collision',
        'gdk_epoch' => null,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => 'sealed',
        'created_at' => $createdAt,
    ]);
}

// What an older build wrote: the bare keyring fingerprint, with no statement
// about how far the pass that stamped it could reach.
function buriedHoldStampAsOlderBuildDid(User $user, string $at): void
{
    /** @var HistoryReprojector $reprojector */
    $reprojector = app(HistoryReprojector::class);

    app(DatabaseManager::class)->connection()->table('sync_encryption_state')
        ->where('user_id', $user->id)
        ->update([
            'history_reprojected_at' => $at,
            'reprojected_keyring_fingerprint' => $reprojector->keyringFingerprint((int) $user->id),
        ]);
}

it('looks again at a hold the previous build stamped a watermark over', function (): void {
    $user = buriedHoldUser();
    $session = buriedHoldEnroll($user);

    buriedHoldQuarantine($user, '2026-09-10 22:44:19');
    buriedHoldStampAsOlderBuildDid($user, '2026-09-10 22:48:53');

    /** @var HistoryReprojector $reprojector */
    $reprojector = app(HistoryReprojector::class);
    /** @var EncryptionRecoveryMarkers $markers */
    $markers = app(EncryptionRecoveryMarkers::class);

    $state = $reprojector->backlogState(
        (int) $user->id,
        $session,
        $markers->historyReprojectedAt((int) $user->id),
        $markers->reprojectedPassIdentity((int) $user->id),
    );

    expect($state)->toBe(SyncBacklogState::Deferred);
});

// The other half, and the reason the window exists: a watermark THIS build
// stamped must still close, or every request replays the whole quarantine.
it('still closes the window on a watermark this build stamped itself', function (): void {
    $user = buriedHoldUser();
    $session = buriedHoldEnroll($user);

    buriedHoldQuarantine($user, '2026-09-10 22:44:19');

    /** @var HistoryReprojector $reprojector */
    $reprojector = app(HistoryReprojector::class);
    /** @var EncryptionRecoveryMarkers $markers */
    $markers = app(EncryptionRecoveryMarkers::class);

    $markers->markHistoryReprojected((int) $user->id, $reprojector->passIdentity((int) $user->id));

    $state = $reprojector->backlogState(
        (int) $user->id,
        $session,
        $markers->historyReprojectedAt((int) $user->id),
        $markers->reprojectedPassIdentity((int) $user->id),
    );

    expect($state)->toBe(SyncBacklogState::None);
});

// A device holding no keyring has no identity to state, and a null marker is
// what an earlier pass left. Prefixing a reach onto nothing would make the two
// compare unequal forever, so every such pass would sweep the whole quarantine.
it('answers nothing on an install that holds no keyring at all', function (): void {
    $user = buriedHoldUser();

    /** @var HistoryReprojector $reprojector */
    $reprojector = app(HistoryReprojector::class);

    expect($reprojector->keyringFingerprint((int) $user->id))->toBeNull()
        ->and($reprojector->passIdentity((int) $user->id))->toBeNull();
});

// The identity is the keyring AND the reach. Asserted apart from the two
// behaviours above so a future build that folds the reach back out fails here
// rather than silently reinstating the burial.
it('names the reach of the build alongside the keyring it evaluated', function (): void {
    $user = buriedHoldUser();
    buriedHoldEnroll($user);

    /** @var HistoryReprojector $reprojector */
    $reprojector = app(HistoryReprojector::class);

    $fingerprint = $reprojector->keyringFingerprint((int) $user->id);

    expect($fingerprint)->toBeString()
        ->and($reprojector->passIdentity((int) $user->id))
        ->not->toBe($fingerprint)
        ->toContain(HistoryReprojector::PASS_REACH)
        ->toContain((string) $fingerprint);
});
