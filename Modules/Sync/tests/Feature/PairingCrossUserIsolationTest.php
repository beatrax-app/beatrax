<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;

uses(RefreshDatabase::class);

/*
 * PairingCrossUserIsolationTest — cross-user isolation (STRIDE T-10-02 / T-12-03
 * extension). A pairing token issued for user A must NOT be consumable by
 * user B's session.
 *
 * RED until Plan 02 ships PairingTokenService with the user_id scope on accept().
 * References the planned FQCN Modules\Sync\Internal\Pairing\PairingTokenService.
 * Failure is "class not found".
 */

function isolationUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('isolation-pass'),
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

it('rejects a pairing token for user A when consumed under user B', function (): void {
    $userA = isolationUser('isolation-a');
    $userB = isolationUser('isolation-b');

    /** @var PairingTokenService $service */
    $service = $this->app->make(PairingTokenService::class);

    // User A issues a token.
    $token = $service->issue((int) $userA->id, 'device-a', str_repeat('a', 64), str_repeat('b', 64));

    // User B attempts to accept user A's token — must be rejected by the
    // user_id scope on accept().
    $result = $service->accept($token, (int) $userB->id, 'device-b', str_repeat('c', 64), str_repeat('d', 64));

    expect($result)->toBeFalse();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // No device_registry row should have been created for user B.
    $leaked = $db->connection()->table('device_registry')
        ->where('user_id', $userB->id)
        ->count();

    expect($leaked)->toBe(0);
});
