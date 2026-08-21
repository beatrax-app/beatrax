<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);

// The both-screen confirmation gate is the only thing keeping an unconfirmed
// or MITM device out of the trusted device_registry, so it must not be drivable
// by one device: confirm() derives the confirming side from the caller's own
// device id rather than from anything the caller can choose.

function trustGateUser(string $username = 'trust-gate-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('trust-pass'),
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

it('does not trust a device that confirms both sides from a single device id', function (): void {
    $user = trustGateUser();

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $accepted = $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    expect($accepted)->not->toBeFalse();

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    // A single responder device trying to drive BOTH confirmation columns by
    // calling confirm() twice with its own device id.
    $service->confirm((int) $row->id, (int) $user->id, 'device-resp', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));
    $service->confirm((int) $row->id, (int) $user->id, 'device-resp', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));

    $after = $db->connection()->table('pairing_tokens')->where('id', $row->id)->first();

    expect($after->initiator_confirmed_at)->toBeNull();
    expect($after->state)->not->toBe('confirmed');

    // device_registry is what deviceKeys() reads, so an absent row here is the
    // responder never having been admitted to the trusted set.
    $device = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', 'device-resp')
        ->first();

    expect($device)->toBeNull();
});

it('rejects a confirm from a device id that owns neither side of the token', function (): void {
    $user = trustGateUser('trust-gate-stranger');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    $service->confirm((int) $row->id, (int) $user->id, 'device-stranger', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));

    $after = $db->connection()->table('pairing_tokens')->where('id', $row->id)->first();

    expect($after->initiator_confirmed_at)->toBeNull();
    expect($after->responder_confirmed_at)->toBeNull();
    expect($after->state)->not->toBe('confirmed');
});
