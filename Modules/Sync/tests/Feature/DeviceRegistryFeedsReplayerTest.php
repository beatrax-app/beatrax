<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

function replayerBridgeUser(string $username = 'replayer-bridge-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('bridge-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('produces a non-empty deviceKeys map that the OpLogReplayer can consume after a confirmed pairing', function (): void {
    $user = replayerBridgeUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $confirmedEd = str_repeat('a', 64);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'device-confirmed',
        'name' => 'Confirmed device',
        'ed25519_public_key_hex' => $confirmedEd,
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-06-15T10:00:00Z',
        'confirmed_at' => '2026-06-15T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-06-15T10:00:00Z',
        'updated_at' => '2026-06-15T10:00:00Z',
    ]);

    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);

    $deviceKeys = $registry->deviceKeys((int) $user->id);

    expect($deviceKeys)->not->toBeEmpty();
    expect($deviceKeys)->toHaveKey('device-confirmed');

    $replayer = new OpLogReplayer(
        $db,
        $deviceKeys,
        $this->app->make(MergeRulesRegistry::class),
    );

    expect($replayer)->toBeInstanceOf(OpLogReplayer::class);
});
