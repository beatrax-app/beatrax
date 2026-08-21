<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Internal\Encryption\PreMigrationSnapshot;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// A column registered as sensitive whose TABLE the sweep never visits stays
// readable on disk for the life of the install, while new rows encrypt fine —
// so nothing on screen ever looks wrong.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
function esweepUser(): User
{
    return User::query()->create([
        'username' => 'esweep-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

it('sweeps every table PROJECTION_COLUMNS names, notifications included', function (): void {
    $user = esweepUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Written before encryption was enabled, which is the only way a row can
    // be sitting in the clear when the sweep runs.
    $db->connection()->table('notifications')->insert([
        'id' => str_repeat('a', 64),
        'user_id' => $user->id,
        'state' => 'open',
        'title' => 'Zilveren Kruis premium is due',
        'body' => 'Your health insurer takes EUR 142.10 on the 24th.',
        'params' => json_encode(['merchant' => 'Zilveren Kruis'], JSON_THROW_ON_ERROR),
        'trigger_type' => 'bill_due',
        'created_at' => '2026-07-01 09:00:00',
        'updated_at' => '2026-07-01 09:00:00',
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(EncryptionMigrationService::class)->migrate($user, $session);

    $row = $db->connection()->table('notifications')->where('user_id', $user->id)->first();

    expect($row->title)->not->toBe('Zilveren Kruis premium is due');
    expect($row->body)->not->toBe('Your health insurer takes EUR 142.10 on the 24th.');
    expect($row->trigger_type)->not->toBe('bill_due');

    /** @var SensitiveColumnCodec $codec */
    $codec = app(SensitiveColumnCodec::class);
    expect($codec->decryptValue('notifications', 'title', (string) $row->title, (int) $user->id, $session)['value'])
        ->toBe('Zilveren Kruis premium is due');
    expect($codec->decryptValue('notifications', 'trigger_type', (string) $row->trigger_type, (int) $user->id, $session)['value'])
        ->toBe('bill_due');
});

// The sweep and the rollback snapshot are driven off one list. Two lists is how
// a registered column came to be skipped in the first place.
it('captures and restores the same tables the sweep encrypts', function (): void {
    expect(array_keys(PreMigrationSnapshot::PROJECTION_COLUMNS))
        ->toContain('notifications', 'transactions', 'counterparties', 'tax_transaction_tags', 'transaction_splits');
});
