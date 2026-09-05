<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Modules\Mobile\Internal\Sync\SyncAttemptOutcome;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;

uses(RefreshDatabase::class);

// The relay keeps a blob until the recipient confirms it away. A leg that
// downloads and discards leaves every blob standing until the 30-day TTL and
// re-downloads the whole mailbox on every tick, while telling the reader the
// sync worked -- a sentence earned by nothing but an HTTP round-trip.

function darrSyncingPhone(): array
{
    $user = User::query()->create([
        'username' => 'darr-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('darr-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    /** @var RelayConfig $relayConfig */
    $relayConfig = app(RelayConfig::class);
    $relayConfig->setEndpointUrl('https://relay.fixture.test');

    return [(int) $user->id, $session];
}

/** @param  array<int, array<string, mixed>>  $blobs */
function darrFakeRelay(array $blobs, array &$deleted, int $confirmStatus = 200): void
{
    Http::fake(function (Request $request) use ($blobs, &$deleted, $confirmStatus) {
        if ($request->method() === 'DELETE') {
            $deleted[] = $request->url();

            return Http::response('', $confirmStatus);
        }

        return Http::response(['blobs' => $blobs], 200);
    });
}

function darrRow(int $id, string $type): array
{
    return [
        'id' => $id,
        'sender_did' => 'device-desktop',
        'blob' => base64_encode((string) json_encode(['type' => $type])),
        'created_at' => '2026-09-05T10:00:00Z',
    ];
}

afterEach(function (): void {
    $secretsDir = UserDataPathService::secretsPath();

    foreach (['sync-relay-drain-tokens.json', 'sync-relay-drain-registry.json'] as $name) {
        $path = $secretsDir.DIRECTORY_SEPARATOR.$name;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $relayPath = UserDataPathService::appPath('sync/relay.json');
    if (is_file($relayPath)) {
        @unlink($relayPath);
    }
});

it('confirms the epoch wrap it drained, so the relay stops re-serving it', function (): void {
    [$userId, $session] = darrSyncingPhone();
    $deleted = [];
    darrFakeRelay([darrRow(41, GdkEpochDeliveryGateway::MSG_EPOCH_WRAP)], $deleted);

    $outcome = app(MobileSyncTriggerService::class)->attempt($userId, $session);

    expect($outcome)->toBe(SyncAttemptOutcome::Synced);
    expect($deleted)->toHaveCount(1);
    expect($deleted[0])->toContain('/relay/drain/41');
});

// The pairing poll is that frame's reader and has not seen it yet. Confirming
// it here deletes a handshake step off the relay, which is the mirror of the
// bug above and is why this leg cannot simply confirm everything it drains.
it('leaves another protocol frame on the relay and still reports the leg reached', function (): void {
    [$userId, $session] = darrSyncingPhone();
    $deleted = [];
    darrFakeRelay([darrRow(42, 'PAIR_CONFIRM')], $deleted);

    $outcome = app(MobileSyncTriggerService::class)->attempt($userId, $session);

    expect($outcome)->toBe(SyncAttemptOutcome::Synced);
    expect($deleted)->toBe([]);
});

// What `true` now means: not "the relay answered" but "everything this leg is
// the reader of is off the relay". A confirm the relay refused leaves the blob
// standing, so the tick has not finished the work the sentence claims.
it('does not report a sync when the wrap it drained is still on the relay', function (): void {
    [$userId, $session] = darrSyncingPhone();
    $deleted = [];
    darrFakeRelay([darrRow(43, GdkEpochDeliveryGateway::MSG_EPOCH_WRAP)], $deleted, 503);

    $outcome = app(MobileSyncTriggerService::class)->attempt($userId, $session);

    expect($outcome)->toBe(SyncAttemptOutcome::Unreachable);
    expect($deleted)->toHaveCount(1);
});

it('reports the leg reached when the mailbox is empty', function (): void {
    [$userId, $session] = darrSyncingPhone();
    $deleted = [];
    darrFakeRelay([], $deleted);

    expect(app(MobileSyncTriggerService::class)->attempt($userId, $session))
        ->toBe(SyncAttemptOutcome::Synced);
    expect($deleted)->toBe([]);
});

it('reports the leg unreached when the relay refuses the drain itself', function (): void {
    [$userId, $session] = darrSyncingPhone();
    Http::fake(['relay.fixture.test/*' => Http::response('nope', 503)]);

    expect(app(MobileSyncTriggerService::class)->attempt($userId, $session))
        ->toBe(SyncAttemptOutcome::Unreachable);
});

// A blob that is not base64 belongs to nobody this leg can speak for. It is
// left standing rather than confirmed away, because the poll that IS its reader
// is the one entitled to decide it will never be decodable.
it('leaves a blob it cannot decode for the poll that owns it', function (): void {
    [$userId, $session] = darrSyncingPhone();
    $deleted = [];
    darrFakeRelay([[
        'id' => 45,
        'sender_did' => 'device-desktop',
        'blob' => 'not-base64!!',
        'created_at' => '2026-09-05T10:00:00Z',
    ]], $deleted);

    expect(app(MobileSyncTriggerService::class)->attempt($userId, $session))
        ->toBe(SyncAttemptOutcome::Synced);
    expect($deleted)->toBe([]);
});

// The one caller that runs keyless. MobilePullCommand builds a console session
// with no app-lock key, so DeviceIdentityLoader answers Locked and attempt()
// returns before the relay leg exists at all. That ordering is what makes
// confirming safe here: nothing this leg confirms was drained by a process that
// could not open it, because such a process never reaches the drain.
it('never drains at all from a scheduled tick that holds no app-lock key', function (): void {
    [$userId, $session] = darrSyncingPhone();
    unset($session);
    $deleted = [];
    darrFakeRelay([darrRow(44, GdkEpochDeliveryGateway::MSG_EPOCH_WRAP)], $deleted);

    $requests = 0;
    Http::globalRequestMiddleware(function ($request) use (&$requests) {
        $requests++;

        return $request;
    });

    $keyless = new Store('darr-cold-process', new ArraySessionHandler(120));
    app()->instance('session.store', $keyless);
    app()->instance(Session::class, $keyless);

    expect(Artisan::call('sync:mobile-pull'))->toBe(0);

    expect($requests)->toBe(0);
    expect($deleted)->toBe([]);
    expect(app(MobileSyncTriggerService::class)->attempt($userId, $keyless))
        ->toBe(SyncAttemptOutcome::Locked);

    // And it says so. A tick that reached nothing used to exit 0 in silence,
    // which is the same console output as a tick with nothing to do.
    expect(Artisan::output())->toContain('skipped 1 user');
});
