<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);

function tokenUser(string $username = 'token-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('token-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('stores the token as a SHA-256 hash, never plaintext', function (): void {
    $user = tokenUser();

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    expect($row)->not->toBeNull();
    expect($row->token_hash)->not->toBe($token);
    expect($row->token_hash)->toBe(hash('sha256', $token));
});

it('refuses a token that has already been used', function (): void {
    $user = tokenUser('token-used');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $first = $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    expect($first)->not->toBeFalse();

    $second = $service->accept($token, (int) $user->id, 'device-resp-2', str_repeat('e', 64), str_repeat('f', 64));
    expect($second)->toBeFalse();
});

it('does not shorten the token TTL when the responder accepts early', function (): void {
    $user = tokenUser('token-early-accept');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Issued at 10:00:00 → TTL +10m → original expiry 10:10:00.
    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $issued = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $originalExpiry = CarbonImmutable::parse($issued->expires_at);

    // Responder accepts early (30s later). The +5m grace floor (10:05:30) is
    // EARLIER than the original 10:10:00 expiry — the window must not shrink.
    CarbonImmutable::setTestNow('2026-06-15 10:00:30');
    $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    $accepted = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $newExpiry = CarbonImmutable::parse($accepted->expires_at);

    expect($newExpiry->greaterThanOrEqualTo($originalExpiry))->toBeTrue();
});

it('extends the token TTL when the responder accepts near the original expiry', function (): void {
    $user = tokenUser('token-late-accept');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Issued at 10:00:00 → original expiry 10:10:00.
    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $issued = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $originalExpiry = CarbonImmutable::parse($issued->expires_at);

    // Responder accepts at 10:09:00 → grace floor 10:14:00 > original → grows.
    CarbonImmutable::setTestNow('2026-06-15 10:09:00');
    $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    $accepted = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $newExpiry = CarbonImmutable::parse($accepted->expires_at);

    expect($newExpiry->greaterThan($originalExpiry))->toBeTrue();
});

// The grace rule is reached by two transports: the typed code goes through
// accept(), the relayed/LAN frame through applyResponderAccept(). The pair
// below is the same pair above, driven through the second entry point.
it('does not shorten the token TTL when a relayed responder-accept arrives early', function (): void {
    $user = tokenUser('token-relayed-early-accept');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Issued at 10:00:00 → TTL +10m → original expiry 10:10:00.
    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $issued = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    // The frame lands 30s later. Its grace floor (10:05:30) is EARLIER than
    // the original expiry, so the window must be left exactly as it was.
    CarbonImmutable::setTestNow('2026-06-15 10:00:30');
    $bound = $service->applyResponderAccept(
        (int) $user->id,
        hash('sha256', $token),
        'device-resp',
        str_repeat('c', 64),
        str_repeat('d', 64),
    );

    expect($bound)->not->toBeFalse();

    $accepted = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    expect($accepted->state)->toBe('awaiting_confirm');
    expect($accepted->expires_at)->toBe($issued->expires_at);
});

it('extends the token TTL when a relayed responder-accept arrives near the original expiry', function (): void {
    $user = tokenUser('token-relayed-late-accept');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $issued = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    // The frame lands at 10:09:00 → grace floor 10:14:00 > original → grows.
    CarbonImmutable::setTestNow('2026-06-15 10:09:00');
    $bound = $service->applyResponderAccept(
        (int) $user->id,
        hash('sha256', $token),
        'device-resp',
        str_repeat('c', 64),
        str_repeat('d', 64),
    );

    expect($bound)->not->toBeFalse();

    $accepted = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    expect($accepted->expires_at)->not->toBe($issued->expires_at);
    expect($accepted->expires_at)->toBe(Instant::zulu(CarbonImmutable::now()->addMinutes(5)));
});

it('rejects an accept whose responder public key is not valid 64-char hex', function (): void {
    $user = tokenUser('token-bad-key');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    // A non-hex / wrong-length responder key must be rejected cleanly (false),
    // not persisted and later exploded as a SodiumException.
    $result = $service->accept($token, (int) $user->id, 'device-resp', 'not-hex', str_repeat('d', 64));

    expect($result)->toBeFalse();
});

it('prunes expired and terminal token rows on the next issue', function (): void {
    $user = tokenUser('token-prune');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    expect($db->connection()->table('pairing_tokens')->where('user_id', $user->id)->count())->toBe(1);

    // Time passes well past the first token's TTL, then the user re-issues.
    CarbonImmutable::setTestNow('2026-06-15 10:20:00');
    $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $rows = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows->first()->state)->toBe('pending');
});

it('refuses a token whose window has already closed', function (): void {
    $user = tokenUser('token-expired');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    // Advance time well past the token TTL.
    CarbonImmutable::setTestNow('2026-06-15 11:00:00');

    $result = $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    expect($result)->toBeFalse();
});

// First-binding-wins used to be absolute. Anyone on the same network could
// answer an mDNS browse, harvest the token hash the responder sends in the
// clear, and race an accept in first — after which the real phone could never
// bind, however many times it tried. A binding nobody has confirmed is not yet
// a decision, so it can be replaced; the moment either side confirms, it is.

function acceptedToken(PairingTokenService $service, User $user, string $responderDeviceId): string
{
    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $hash = hash('sha256', $token);

    $service->applyResponderAccept(
        (int) $user->id,
        $hash,
        $responderDeviceId,
        str_repeat('c', 64),
        str_repeat('d', 64),
        'Squatter',
    );

    return $hash;
}

it('lets a later responder replace a binding nobody has confirmed', function (): void {
    $user = tokenUser('rebind-user');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);
    $hash = acceptedToken($service, $user, '11111111-2222-4333-8444-555555555555');

    $honest = '99999999-8888-4777-8666-555555555555';

    expect($service->applyResponderAccept(
        (int) $user->id,
        $hash,
        $honest,
        str_repeat('e', 64),
        str_repeat('f', 64),
        'Real phone',
    ))->not->toBeFalse();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    expect($row->responder_device_id)->toBe($honest);
});

it('refuses a different responder once a side has confirmed', function (): void {
    $user = tokenUser('rebind-confirmed-user');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);
    $bound = '11111111-2222-4333-8444-555555555555';
    $hash = acceptedToken($service, $user, $bound);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('pairing_tokens')
        ->where('user_id', $user->id)
        ->update(['initiator_confirmed_at' => CarbonImmutable::now()->toIso8601String()]);

    expect($service->applyResponderAccept(
        (int) $user->id,
        $hash,
        '99999999-8888-4777-8666-555555555555',
        str_repeat('e', 64),
        str_repeat('f', 64),
        'Too late',
    ))->toBeFalse();

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    expect($row->responder_device_id)->toBe($bound);
});

// Redelivery is the ordinary case — the same frame arriving twice must not be
// treated as a second responder trying to take the slot.
it('stays idempotent for a redelivery of the same responder', function (): void {
    $user = tokenUser('rebind-idempotent-user');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);
    $bound = '11111111-2222-4333-8444-555555555555';
    $hash = acceptedToken($service, $user, $bound);

    expect($service->applyResponderAccept(
        (int) $user->id,
        $hash,
        $bound,
        str_repeat('c', 64),
        str_repeat('d', 64),
        'Squatter',
    ))->not->toBeFalse();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    expect($db->connection()->table('pairing_tokens')->where('user_id', $user->id)->count())->toBe(1);
});

// Allowing an unconfirmed binding to be replaced fixes a denial, but on its own
// it opens a capture: the words are derived once, and a responder that rebinds
// between the reading and the tap would inherit a confirmation nobody gave it.
it('refuses a confirmation once the keys behind the shown words have changed', function (): void {
    $user = tokenUser('capture-guard-user');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);
    $hash = acceptedToken($service, $user, '11111111-2222-4333-8444-555555555555');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    // What the human compared, taken while the honest responder was bound.
    $shown = PairingSafetyDigest::forToken((int) $row->id, (int) $user->id);

    $service->applyResponderAccept(
        (int) $user->id,
        $hash,
        '99999999-8888-4777-8666-555555555555',
        str_repeat('e', 64),
        str_repeat('f', 64),
        'Squatter',
    );

    expect($service->confirm((int) $row->id, (int) $user->id, 'device-init', $shown))->toBeNull();

    $after = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    expect($after->initiator_confirmed_at)->toBeNull();
});

it('accepts a confirmation whose keys are still the ones that were shown', function (): void {
    $user = tokenUser('capture-guard-ok-user');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);
    acceptedToken($service, $user, '11111111-2222-4333-8444-555555555555');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    $service->confirm(
        (int) $row->id,
        (int) $user->id,
        'device-init',
        PairingSafetyDigest::forToken((int) $row->id, (int) $user->id),
    );

    $after = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    expect($after->initiator_confirmed_at)->not->toBeNull();
});

// An empty digest is a caller that never showed anything, which cannot have had
// a comparison made against it.
it('refuses a confirmation carrying no record of what was shown', function (): void {
    $user = tokenUser('capture-guard-empty-user');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);
    acceptedToken($service, $user, '11111111-2222-4333-8444-555555555555');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    expect($service->confirm((int) $row->id, (int) $user->id, 'device-init', ''))->toBeNull();
});

function selfDevice(User $user, string $deviceId): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toIso8601String();

    $db->connection()->table('device_registry')->insert([
        'user_id' => (int) $user->id,
        'device_id' => $deviceId,
        'name' => 'This device',
        'ed25519_public_key_hex' => str_repeat('1', 64),
        'x25519_public_key_hex' => str_repeat('2', 64),
        'safety_number_words' => 'one two three four five six',
        'is_self' => 1,
        'paired_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// The phone is the responder on its own row. A hostile peer answering the
// phone's own pull hands back a PAIR_RESPONDER_ACCEPT naming itself, and the
// rebind rule would take the slot — leaving the phone owning neither side and
// unable to confirm its own pairing ever again.
it('refuses a responder accept that would take over this device own side', function (): void {
    $user = tokenUser('own-side-user');
    $phone = '11111111-2222-4333-8444-555555555555';
    selfDevice($user, $phone);

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    // The phone binds its OWN side through accept(), never through a frame.
    $token = $service->issue((int) $user->id, 'the-desktop', str_repeat('a', 64), str_repeat('b', 64));
    expect($service->accept($token, (int) $user->id, $phone, str_repeat('c', 64), str_repeat('d', 64)))
        ->not->toBeFalse();

    expect($service->applyResponderAccept(
        (int) $user->id,
        hash('sha256', $token),
        '99999999-8888-4777-8666-555555555555',
        str_repeat('e', 64),
        str_repeat('f', 64),
        'Hostile peer',
    ))->toBeFalse();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    expect($row->responder_device_id)->toBe($phone);
    expect($row->responder_ed25519_pub_hex)->not->toBe(str_repeat('e', 64));
});

// A device binds its own side through accept(), never through an inbound frame,
// so one naming this device as the responder came from somewhere it should not
// have and is refused rather than written.
it('refuses a responder accept that names this device as the responder', function (): void {
    $user = tokenUser('own-name-user');
    $desktop = '77777777-6666-4555-8444-333333333333';
    selfDevice($user, $desktop);

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);
    $token = $service->issue((int) $user->id, $desktop, str_repeat('a', 64), str_repeat('b', 64));

    expect($service->applyResponderAccept(
        (int) $user->id,
        hash('sha256', $token),
        $desktop,
        str_repeat('e', 64),
        str_repeat('f', 64),
    ))->toBeFalse();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    expect($row->responder_device_id)->toBeNull();
    expect($row->state)->toBe('pending');
});

// The honest case the guard must not break: the desktop is the initiator, and
// the phone's accept binds the far side of its row.
it('still binds a responder that is not this device', function (): void {
    $user = tokenUser('other-side-user');
    $desktop = '77777777-6666-4555-8444-333333333333';
    selfDevice($user, $desktop);

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);
    $token = $service->issue((int) $user->id, $desktop, str_repeat('a', 64), str_repeat('b', 64));

    expect($service->applyResponderAccept(
        (int) $user->id,
        hash('sha256', $token),
        '11111111-2222-4333-8444-555555555555',
        str_repeat('e', 64),
        str_repeat('f', 64),
    ))->not->toBeFalse();
});
