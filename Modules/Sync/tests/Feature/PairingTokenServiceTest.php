<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;

uses(RefreshDatabase::class);

/*
 * PairingTokenServiceTest — D-06/D-13 token single-use + expiry lifecycle.
 *
 * RED until Plan 02 ships PairingTokenService. References the planned FQCN
 * Modules\Sync\Internal\Pairing\PairingTokenService. Failure is "class not found".
 */

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

it('it_rejects_already_used_token', function (): void {
    $user = tokenUser('token-used');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    // First accept succeeds.
    $first = $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    expect($first)->not->toBeFalse();

    // Second accept of the same token must be rejected (single-use).
    $second = $service->accept($token, (int) $user->id, 'device-resp-2', str_repeat('e', 64), str_repeat('f', 64));
    expect($second)->toBeFalse();
});

it('it_rejects_expired_token', function (): void {
    $user = tokenUser('token-expired');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));

    // Advance time well past the token TTL.
    CarbonImmutable::setTestNow('2026-06-15 11:00:00');

    $result = $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));
    expect($result)->toBeFalse();
});
