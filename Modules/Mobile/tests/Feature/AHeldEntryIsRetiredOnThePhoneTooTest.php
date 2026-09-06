<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;

uses(RefreshDatabase::class);

// The desktop runs the recovery pass after every response, from a middleware
// the mobile shell does not register. So a row a keyless writer left in the
// clear, and a peer entry held for a key that had not arrived, were retried on
// one shell and on the other waited for a setup that was already over.

function phoneHeldUser(): array
{
    $user = User::query()->create([
        'username' => 'held-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(EncryptionMigrationService::class)->migrate($user, $session);
    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    return [$user, $session];
}

it('runs the held-entry recovery pass on a sync tick, because no middleware runs it here', function (): void {
    [$user, $session] = phoneHeldUser();

    $id = str_repeat('c', 64);
    $title = 'Budget nearly spent';

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // What a queue worker holding no key leaves behind: a registered column
    // written in the clear, and the coverage digest never stamped — the two
    // cannot honestly coexist, since the pass that stamps it would have sealed
    // the row.
    $db->connection()->table('notifications')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'state' => 'open',
        'title' => $title,
        'body' => 'Groceries is at 92% with 9 days to go.',
        'params' => json_encode(['budget' => 'Groceries'], JSON_THROW_ON_ERROR),
        'trigger_type' => 'budget_nudge',
        'created_at' => '2026-08-22 16:00:09',
        'updated_at' => '2026-08-22 16:00:09',
    ]);

    $db->connection()->table('sync_encryption_state')
        ->where('user_id', $user->id)
        ->update(['resealed_columns_digest' => null]);

    expect($db->connection()->table('notifications')->where('id', $id)->value('title'))->toBe($title);

    // No LAN host and no relay configured: this is the bare tick, so what the
    // assertion below can be crediting is the recovery pass and nothing else.
    app(MobileSyncTriggerService::class)->attempt((int) $user->id, $session);

    expect($db->connection()->table('notifications')->where('id', $id)->value('title'))
        ->not->toBe($title, 'the phone left a registered column sitting in the clear');
});
