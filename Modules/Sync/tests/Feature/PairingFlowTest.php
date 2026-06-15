<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;

uses(RefreshDatabase::class);

/*
 * PairingFlowTest — PAIR-02 end-to-end issue -> accept -> both-confirm.
 *
 * RED until Plans 02/03 ship PairingTokenService + the confirm transitions.
 * Drives the full state machine: a confirmed pairing must flip
 * pairing_tokens.state to 'confirmed' AND create a device_registry row with
 * confirmed_at set. Failure is "class not found".
 */

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

    // 1. Initiator issues a token.
    $token = $service->issue((int) $user->id, 'device-init', $initiatorEd, str_repeat('b', 64));

    // 2. Responder accepts — transitions to awaiting_confirm.
    $accepted = $service->accept($token, (int) $user->id, 'device-resp', $responderEd, str_repeat('d', 64));
    expect($accepted)->not->toBeFalse();

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    expect($row->state)->toBe('awaiting_confirm');

    // 3. Both sides confirm the safety-number. The confirming side is derived
    //    from the caller's OWN device id (CR-01) — pass each device's real id.
    $service->confirm((int) $row->id, (int) $user->id, 'device-init');
    $service->confirm((int) $row->id, (int) $user->id, 'device-resp');

    $confirmed = $db->connection()->table('pairing_tokens')->where('id', $row->id)->first();
    expect($confirmed->state)->toBe('confirmed');

    // 4. A device_registry row for the responder device exists with confirmed_at set.
    $device = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', 'device-resp')
        ->first();

    expect($device)->not->toBeNull();
    expect($device->confirmed_at)->not->toBeNull();
    expect($device->ed25519_public_key_hex)->toBe($responderEd);
});

it('refuses to admit a responder whose device_id collides with the local self-row, leaving self keys intact (WR-05)', function (): void {
    $user = flowUser('flow-collision');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // A local self device row.
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

    // A responder presents the SAME device_id as the self-row.
    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $service->accept($token, (int) $user->id, 'self-device', str_repeat('c', 64), str_repeat('d', 64));

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $service->confirm((int) $row->id, (int) $user->id, 'device-init');
    $service->confirm((int) $row->id, (int) $user->id, 'self-device');

    // The self-row's keys must be untouched — the collision was refused.
    $self = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('is_self', 1)
        ->first();

    expect($self->ed25519_public_key_hex)->toBe(str_repeat('1', 64));
    expect($self->x25519_public_key_hex)->toBe(str_repeat('2', 64));

    // No second (non-self) row was created for the colliding device_id.
    $nonSelf = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('is_self', 0)
        ->count();

    expect($nonSelf)->toBe(0);
});
