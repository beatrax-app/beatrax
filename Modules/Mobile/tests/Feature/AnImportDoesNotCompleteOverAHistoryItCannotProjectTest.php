<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\InitialSyncPuller;
use Modules\Mobile\Internal\Sync\SyncBlockedReason;
use Modules\Mobile\Internal\Sync\SyncPhase;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\PortableKeyMaterial;

uses(RefreshDatabase::class);

// `current_epoch` set is the enrolment pointer, not the key behind it, and the
// step read it as "the keys are installed". A phone whose keyring it cannot
// open therefore ran the re-projection anyway, stamped `reprojected_at`, and
// reported the import Complete over a history every sealed column of which it
// had just recorded as a `strategy_error` hold.
/**
 * @link ../../../../.docs/features/mobile/mobile-initial-sync-gate.md
 */
function strandedUser(): User
{
    return User::query()->create([
        'username' => 'stranded-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('mobile-stranded-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function strandedPeer(DatabaseManager $db, int $userId): string
{
    $peerDeviceId = 'desktop-stranded-'.bin2hex(random_bytes(4));

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $peerDeviceId,
        'name' => 'Fixture Desktop',
        'ed25519_public_key_hex' => bin2hex(random_bytes(32)),
        'x25519_public_key_hex' => bin2hex(random_bytes(32)),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 0,
        'paired_at' => '2026-07-01 00:00:00',
        'confirmed_at' => '2026-07-01 00:00:00',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);

    return $peerDeviceId;
}

function strandedEntries(DatabaseManager $db, int $userId, string $deviceId, int $count): void
{
    $batch = [];

    for ($i = 1; $i <= $count; $i++) {
        $batch[] = [
            'user_id' => $userId,
            'device_id' => $deviceId,
            'table_name' => 'categories',
            'pk' => (string) (6000 + $i),
            'field' => 'name',
            'op_type' => 'set',
            'value' => json_encode('Fixture category '.$i),
            'hlc_l' => $i,
            'hlc_c' => 0,
            'signature' => 'fixture-signature-'.$i,
            'recorded_at' => '2026-07-10 00:00:00',
        ];
    }

    $db->connection()->table('op_log_entries')->insert($batch);
}

it('does not report the import complete while the keyring it enrolled with will not open', function (): void {
    $userId = (int) strandedUser()->id;

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    @unlink(UserDataPathService::appPath('sync/identity/'.$userId.'.enc'));
    app(DeviceIdentityService::class)->generateAndPersist($userId, $session);

    // Enrolled, then stranded: `current_epoch` is committed and the file the
    // key lives in is not there. PairingEncryptionActivation names this state
    // and leaves the phone in it, with no screen offering a way to ask again.
    app(GdkKeyringService::class)->generateAndPersist($userId, $session);
    @unlink(app(PortableKeyMaterial::class)->keyringPath($userId));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $peerDeviceId = strandedPeer($db, $userId);
    strandedEntries($db, $userId, $peerDeviceId, 20);

    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');
    Http::fake(['relay.fixture.test/*' => Http::response(['blobs' => []], 200)]);

    /** @var InitialSyncPuller $puller */
    $puller = app(InitialSyncPuller::class);

    // Two ticks, because the rebuild is announced on the tick BEFORE it runs:
    // the stamp that used to close the import came on the second.
    $puller->pull($userId, $session);
    $progress = $puller->pull($userId, $session);

    $cursor = $db->connection()->table('mobile_sync_progress')
        ->where('user_id', $userId)
        ->where('peer_device_id', $peerDeviceId)
        ->first();

    expect($progress['blocked'])->toBe(SyncBlockedReason::NoKeys)
        ->and($progress['phase'])->not->toBe(SyncPhase::Complete)
        ->and($cursor?->reprojected_at)->toBeNull();
});
