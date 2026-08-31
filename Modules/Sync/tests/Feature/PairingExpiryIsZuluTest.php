<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Public\Dto\PairingPeerIdentity;

uses(RefreshDatabase::class);

// expires_at is compared lexically, so a value written at a local offset sorts
// by its own hour digits against a Zulu now. The app and its sync:serve daemon
// are two processes on one database and need not share a TZ.

const PAIRING_ZULU_SHAPE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

function zuluUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function atTimezone(string $timezone, string $instant): void
{
    date_default_timezone_set($timezone);
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));
}

function expiryOf(int $userId): string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (string) $db->connection()->table('pairing_tokens')
        ->where('user_id', $userId)
        ->orderByDesc('id')
        ->value('expires_at');
}

beforeEach(function (): void {
    $this->originalTimezone = date_default_timezone_get();
    $this->service = app(PairingTokenService::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    date_default_timezone_set($this->originalTimezone);
});

it('writes a Zulu expiry from a device sitting two hours east of UTC', function (): void {
    atTimezone('Europe/Amsterdam', '2026-06-15T18:30:00Z');
    $user = zuluUser('zulu-issue');

    $this->service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    expect(expiryOf((int) $user->id))->toMatch(PAIRING_ZULU_SHAPE);
});

it('writes a Zulu expiry on the row a scanned QR seeds', function (): void {
    atTimezone('Europe/Amsterdam', '2026-06-15T18:30:00Z');
    $user = zuluUser('zulu-seed');

    $this->service->seedFromInitiator((int) $user->id, new PairingPeerIdentity('the-desktop', str_repeat('a', 64), str_repeat('b', 64)), bin2hex(random_bytes(16)));

    expect(expiryOf((int) $user->id))->toMatch(PAIRING_ZULU_SHAPE);
});

it('writes a Zulu expiry when an accept extends the window', function (): void {
    atTimezone('Europe/Amsterdam', '2026-06-15T18:30:00Z');
    $user = zuluUser('zulu-accept');

    $token = $this->service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $this->service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    expect(expiryOf((int) $user->id))->toMatch(PAIRING_ZULU_SHAPE);
});

it('writes a Zulu expiry when a relayed responder accept binds the row', function (): void {
    atTimezone('Europe/Amsterdam', '2026-06-15T18:30:00Z');
    $user = zuluUser('zulu-frame-accept');

    $token = $this->service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    $bound = $this->service->applyResponderAccept(
        (int) $user->id,
        hash('sha256', $token),
        'the-phone',
        str_repeat('c', 64),
        str_repeat('d', 64),
    );

    expect($bound)->not->toBeFalse();
    expect(expiryOf((int) $user->id))->toMatch(PAIRING_ZULU_SHAPE);
});

it('refuses a token that ran out of time while the reader sat west of the writer', function (): void {
    atTimezone('Europe/Amsterdam', '2026-06-15T18:30:00Z');
    $user = zuluUser('zulu-stretched-ttl');

    $token = $this->service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    // One minute past the ten-minute TTL, read by a process that inherited no
    // TZ. A '+02:00' expiry sorts two hours later than the instant it names.
    atTimezone('UTC', '2026-06-15T18:41:00Z');

    expect($this->service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64)))
        ->toBeFalse();
});

it('accepts a token still within its TTL when the reader sits east of the writer', function (): void {
    atTimezone('America/New_York', '2026-06-15T18:30:00Z');
    $user = zuluUser('zulu-good-code');

    $token = $this->service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    // A '-04:00' expiry sorts four hours earlier than the instant it names, so
    // the reader judges a code with nine minutes left already expired.
    atTimezone('UTC', '2026-06-15T18:31:00Z');

    expect($this->service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64)))
        ->not->toBeFalse();
});

it('drops the rows an earlier version wrote at a local offset', function (): void {
    atTimezone('Europe/Amsterdam', '2026-06-15T18:30:00Z');
    $user = zuluUser('zulu-legacy-rows');

    $token = $this->service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    DB::table('pairing_tokens')->insert([
        'user_id' => (int) $user->id,
        'token_hash' => hash('sha256', 'legacy-'.$token),
        'initiator_device_id' => 'device-init',
        'initiator_ed25519_pub_hex' => str_repeat('a', 64),
        'initiator_x25519_pub_hex' => str_repeat('b', 64),
        'state' => 'pending',
        'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        'created_at' => CarbonImmutable::now()->toIso8601String(),
    ]);

    $migration = require base_path(
        'Modules/Sync/Database/Migrations/2026_08_21_000001_drop_pairing_tokens_written_at_a_local_offset.php'
    );
    assert($migration instanceof Migration);
    $migration->up();

    expect(DB::table('pairing_tokens')->pluck('expires_at')->all())
        ->toBe([expiryOf((int) $user->id)]);
});
