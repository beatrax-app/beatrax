<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;

uses(RefreshDatabase::class);

// A pairing token is what admits a new device to an account, so one issued for
// a household member must not be consumable from another member's session.

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

    $token = $service->issue((int) $userA->id, 'device-a', str_repeat('a', 64), str_repeat('b', 64));

    $result = $service->accept($token, (int) $userB->id, 'device-b', str_repeat('c', 64), str_repeat('d', 64));

    expect($result)->toBeFalse();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $leaked = $db->connection()->table('device_registry')
        ->where('user_id', $userB->id)
        ->count();

    expect($leaked)->toBe(0);
});
