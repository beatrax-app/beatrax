<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);

function flowUser(string $username = 'flow-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('flow-pass'),
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

it('completes issue -> accept -> both-confirm: state becomes confirmed and a confirmed device_registry row is created', function (): void {
    $user = flowUser();

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $initiatorEd = str_repeat('a', 64);
    $responderEd = str_repeat('c', 64);

    $token = $service->issue((int) $user->id, 'device-init', $initiatorEd, str_repeat('b', 64));

    $accepted = $service->accept($token, (int) $user->id, 'device-resp', $responderEd, str_repeat('d', 64));
    expect($accepted)->not->toBeFalse();

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    expect($row->state)->toBe('awaiting_confirm');

    // Which side is confirming is derived from the caller's own device id, so
    // each call passes the real one.
    $service->confirm((int) $row->id, (int) $user->id, 'device-init', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));
    $service->confirm((int) $row->id, (int) $user->id, 'device-resp', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));

    $confirmed = $db->connection()->table('pairing_tokens')->where('id', $row->id)->first();
    expect($confirmed->state)->toBe('confirmed');

    $device = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', 'device-resp')
        ->first();

    expect($device)->not->toBeNull();
    expect($device->confirmed_at)->not->toBeNull();
    expect($device->ed25519_public_key_hex)->toBe($responderEd);
});

it('refuses to admit a responder whose device_id collides with the local self-row, leaving self keys intact', function (): void {
    $user = flowUser('flow-collision');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $now = CarbonImmutable::now()->toIso8601String();
    $db->connection()->table('device_registry')->insert([
        'user_id' => (int) $user->id,
        'device_id' => 'self-device',
        'name' => 'This device (Mac)',
        'ed25519_public_key_hex' => str_repeat('1', 64),
        'x25519_public_key_hex' => str_repeat('2', 64),
        'safety_number_words' => 'self words',
        'is_self' => 1,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // A responder presenting this device's own id.
    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $service->accept($token, (int) $user->id, 'self-device', str_repeat('c', 64), str_repeat('d', 64));

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $service->confirm((int) $row->id, (int) $user->id, 'device-init', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));
    $service->confirm((int) $row->id, (int) $user->id, 'self-device', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));

    $self = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('is_self', 1)
        ->first();

    expect($self->ed25519_public_key_hex)->toBe(str_repeat('1', 64));
    expect($self->x25519_public_key_hex)->toBe(str_repeat('2', 64));

    $nonSelf = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('is_self', 0)
        ->count();

    expect($nonSelf)->toBe(0);
});
