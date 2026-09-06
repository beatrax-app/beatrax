<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Enums\SyncOverallStatus;
use Modules\Sync\Public\Http\Livewire\SyncStatusSection;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\SyncStatusService;
use Modules\Sync\Tests\Support\StrandedSessionClock;

uses(RefreshDatabase::class);

// The row is written 'active' by authenticate() and 'closed' by close(). When a
// process dies the second never runs, so nothing repairs the row and the reader
// is told a sync is in progress for as long as the install lasts — which also
// suppresses "offline" and "up to date", both of which rank below it.
//
// Only the reader can end this, because the writer that would have is the one
// that died. Believing the row for a bounded time is what makes that safe, and
// it is only honest while a LIVE session keeps stamping the column its own
// schema describes as the instant a valid message last arrived.

const STRANDED_NOW = '2026-09-05T12:00:00Z';

function strandedSessionUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function strandedSessionFreezeClock(string $at = STRANDED_NOW): StrandedSessionClock
{
    $clock = new StrandedSessionClock(CarbonImmutable::parse($at));
    app()->instance(Clock::class, $clock);

    return $clock;
}

function strandedSessionRow(User $user, string $peerDeviceId, string $status, ?string $lastSeenAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $user->id,
        'local_device_id' => 'this-device',
        'peer_device_id' => $peerDeviceId,
        'status' => $status,
        'error_message' => null,
        'last_seen_at' => $lastSeenAt,
        'connected_at' => $lastSeenAt,
        'created_at' => '2026-09-05T09:00:00Z',
        'updated_at' => $lastSeenAt ?? '2026-09-05T09:00:00Z',
    ]);
}

it('stops calling a sync in progress out of a row whose process is gone', function (): void {
    $user = strandedSessionUser('stranded-peer');
    strandedSessionFreezeClock();

    strandedSessionRow($user, 'peer-gone', 'active', '2026-09-05T11:00:00Z');

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))
        ->not->toBe(SyncOverallStatus::Syncing, 'an hour-old active row is a strand, not an exchange in flight')
        ->and(app(SyncStatusService::class)->overallStatus((int) $user->id))
        ->toBe(SyncOverallStatus::Offline);
});

// The other half, so the fix cannot be "an active row never counts". A session
// that stamped the column a moment ago is exactly the case the status exists
// to report, and a bound that swallowed it would be the same defect inverted.
it('still calls a freshly stamped active row a sync in progress', function (): void {
    $user = strandedSessionUser('live-peer');
    strandedSessionFreezeClock();

    strandedSessionRow($user, 'peer-live', 'active', '2026-09-05T11:59:30Z');

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))
        ->toBe(SyncOverallStatus::Syncing);
});

// A strand must not suppress the answer the reader can act on either: the whole
// cost of the bug was that this row outranked both of the states below it.
it('lets a peer that finished an exchange be reported over a strand beside it', function (): void {
    $user = strandedSessionUser('stranded-and-settled');
    strandedSessionFreezeClock();

    strandedSessionRow($user, 'peer-gone', 'active', '2026-09-05T11:00:00Z');
    strandedSessionRow($user, 'peer-done', 'closed', '2026-09-05T11:30:00Z');

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))
        ->toBe(SyncOverallStatus::AllSynced);
});

it('draws no live dot on the peer row a strand belongs to, and still draws one for a live peer', function (): void {
    $user = strandedSessionUser('stranded-dot');
    $this->actingAs($user);
    strandedSessionFreezeClock();

    strandedSessionRow($user, 'peer-gone', 'active', '2026-09-05T11:00:00Z');

    $strandedOnly = RenderedMarkup::of(Livewire::test(SyncStatusSection::class)->html());

    expect($strandedOnly->count('[aria-label="'.Lang::get('sync::status.dot_online').'"]'))
        ->toBe(0, 'the pulsing blue dot says a peer is online right now, and nothing is');

    strandedSessionRow($user, 'peer-live', 'active', '2026-09-05T11:59:30Z');

    $withLive = RenderedMarkup::of(Livewire::test(SyncStatusSection::class)->html());

    expect($withLive->count('[aria-label="'.Lang::get('sync::status.dot_online').'"]'))
        ->toBe(1, 'exactly the live peer keeps its dot');
});

// What makes the bound above safe rather than a new lie: the column's own
// schema calls it the instant a valid encrypted message last arrived, and until
// now only the handshake and the close ever wrote it. A session that runs for
// an hour was indistinguishable from one whose process died at minute one.
it('stamps the session row when a valid message arrives, so a long live session is not read as a strand', function (): void {
    $user = strandedSessionUser('heartbeat-peer');
    $clock = strandedSessionFreezeClock();

    $signingKeys = sodium_crypto_sign_keypair();
    $signingSecret = sodium_crypto_sign_secretkey($signingKeys);
    $signingPublicHex = sodium_bin2hex(sodium_crypto_sign_publickey($signingKeys));

    $senderKx = sodium_crypto_kx_keypair();
    $receiverKx = sodium_crypto_kx_keypair();
    $senderPublic = sodium_crypto_kx_publickey($senderKx);
    $receiverPublic = sodium_crypto_kx_publickey($receiverKx);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    foreach ([
        ['sender-device', sodium_bin2hex($senderPublic), 0],
        ['receiver-device', sodium_bin2hex($receiverPublic), 1],
    ] as [$deviceId, $x25519Hex, $isSelf]) {
        $db->connection()->table('device_registry')->insert([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'name' => $deviceId,
            'ed25519_public_key_hex' => $signingPublicHex,
            'x25519_public_key_hex' => $x25519Hex,
            'safety_number_words' => 'a b c d e f',
            'is_self' => $isSelf,
            'paired_at' => STRANDED_NOW,
            'confirmed_at' => STRANDED_NOW,
            'created_at' => STRANDED_NOW,
            'updated_at' => STRANDED_NOW,
        ]);
    }

    $initiator = NoiseHandshakeState::initIkInitiator(sodium_crypto_kx_secretkey($senderKx), $senderPublic, $receiverPublic);
    $responder = NoiseHandshakeState::initIkResponder(sodium_crypto_kx_secretkey($receiverKx), $receiverPublic);
    $responder->readMessage($initiator->writeMessage(''));
    $initiator->readMessage($responder->writeMessage(''));

    [$initiatorSend, $initiatorRecv, $peerStaticToInitiator] = $initiator->split();
    [$responderSend, $responderRecv, $peerStaticToResponder] = $responder->split();

    $senderNoise = new NoiseSession($initiatorSend, $initiatorRecv, $peerStaticToInitiator);

    $deviceKeys = ['sender-device' => $signingPublicHex];
    $session = new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(db: $db, deviceKeys: $deviceKeys),
        framer: new TransportFramer,
        db: $db,
        clock: $clock,
    );

    expect($session->authenticate(new NoiseSession($responderSend, $responderRecv, $peerStaticToResponder), (int) $user->id, 'receiver-device'))
        ->toBeTrue();

    $stampedAtHandshake = $db->connection()->table('sync_sessions')->where('user_id', $user->id)->value('last_seen_at');

    $signer = new DeviceKeySigner;
    $unsigned = new OpLogEntry(
        table: 'transactions',
        pk: 7,
        field: 'note',
        value: '"still here"',
        hlcL: 1_757_073_600_000,
        hlcC: 1,
        deviceId: 'sender-device',
        opType: OpType::Set,
        signature: '',
        userId: (int) $user->id,
    );
    $entry = new OpLogEntry(
        table: $unsigned->table,
        pk: $unsigned->pk,
        field: $unsigned->field,
        value: $unsigned->value,
        hlcL: $unsigned->hlcL,
        hlcC: $unsigned->hlcC,
        deviceId: $unsigned->deviceId,
        opType: $unsigned->opType,
        signature: $signer->sign($unsigned->signingPayload(), $signingSecret),
        userId: $unsigned->userId,
    );

    $clock->travelTo(CarbonImmutable::parse('2026-09-05T12:30:00Z'));

    $session->receiveOps($senderNoise->encrypt((new TransportFramer)->encode([$entry])), (int) $user->id, $deviceKeys);

    $stampedAfterMessage = $db->connection()->table('sync_sessions')->where('user_id', $user->id)->value('last_seen_at');

    expect($stampedAfterMessage)->not->toBe(
        $stampedAtHandshake,
        'a session that has been carrying messages for half an hour still reads as last seen at the handshake',
    );

    expect(app(SyncStatusService::class)->overallStatus((int) $user->id))
        ->toBe(SyncOverallStatus::Syncing, 'and the reader is told the exchange it is actually in');
});
