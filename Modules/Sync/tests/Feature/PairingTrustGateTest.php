<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;

uses(RefreshDatabase::class);

/*
 * PairingTrustGateTest — CR-01 regression.
 *
 * The both-screen confirmation gate (D-07) is the sole defense that keeps an
 * unconfirmed / MITM device out of the trusted device_registry. These tests
 * prove the gate cannot be driven by a SINGLE device: confirm() derives the
 * confirming side from the caller's OWN device id, so one device can never
 * satisfy bothConfirmed() by confirming both columns.
 */

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

it('does not trust a device that confirms both sides from a single device id (CR-01)', function (): void {
    $user = trustGateUser();

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $accepted = $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    expect($accepted)->not->toBeFalse();

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    // A single responder device tries to drive BOTH confirmation columns by
    // calling confirm() twice with its own device id. The side is derived from
    // the device id, so only the responder column is ever written.
    $service->confirm((int) $row->id, (int) $user->id, 'device-resp');
    $service->confirm((int) $row->id, (int) $user->id, 'device-resp');

    $after = $db->connection()->table('pairing_tokens')->where('id', $row->id)->first();

    // The initiator never confirmed — the gate must remain closed.
    expect($after->initiator_confirmed_at)->toBeNull();
    expect($after->state)->not->toBe('confirmed');

    // No device was admitted to the trusted registry: the responder is absent
    // from deviceKeys() (its confirmed_at was never set).
    $device = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', 'device-resp')
        ->first();

    expect($device)->toBeNull();
});

it('rejects a confirm from a device id that owns neither side of the token (CR-01)', function (): void {
    $user = trustGateUser('trust-gate-stranger');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();

    // A device that is neither the initiator nor the responder must not be able
    // to write any confirmation column.
    $service->confirm((int) $row->id, (int) $user->id, 'device-stranger');

    $after = $db->connection()->table('pairing_tokens')->where('id', $row->id)->first();

    expect($after->initiator_confirmed_at)->toBeNull();
    expect($after->responder_confirmed_at)->toBeNull();
    expect($after->state)->not->toBe('confirmed');
});
