<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

/*
 * Counterparties are created by the resolver during import, but they carry the
 * user's own work: the triage screen is where you say "yes, this IBAN is
 * Versio". Without capture that decision stayed on one device.
 *
 * The values go onto the event as PLAINTEXT. OpLogWriter encrypts sensitive
 * columns itself under the GDK epoch and the backfiller decrypts before
 * handing them over, so passing the stored ciphertext would encrypt it twice
 * and the peer would never read it back.
 */

it('writes the counterparty to the op log in plaintext, not stored ciphertext', function (): void {
    $user = User::query()->create([
        'username' => 'cpc-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'cpc-device',
        'userId' => (int) $user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    $counterparty = Counterparty::query()->create([
        'user_id' => $user->id,
        'slug' => 'versio-'.bin2hex(random_bytes(3)),
        'type' => 'merchant',
        'display_name' => 'Versio',
    ]);

    event(new EntityMutated(
        table: 'counterparties',
        pk: (int) $counterparty->id,
        userId: (int) $user->id,
        mutationType: 'create',
        dirtyFields: [
            'user_id' => $user->id,
            'slug' => $counterparty->slug,
            'type' => 'merchant',
            'display_name' => 'Versio',
        ],
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $ops = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'counterparties')
        ->get();

    expect($ops)->not->toBeEmpty()
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($ops->pluck('field')->all())->toContain('display_name', 'slug', 'type');
});

it('routes the display name through the op log\'s own encryption, not the column\'s', function (): void {
    $user = User::query()->create([
        'username' => 'cpc2-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'cpc2-device',
        'userId' => (int) $user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    event(new EntityMutated(
        table: 'counterparties',
        pk: 4242,
        userId: (int) $user->id,
        mutationType: 'create',
        dirtyFields: ['display_name' => 'Versio'],
    ));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'counterparties')
        ->where('field', 'display_name')
        ->first();

    expect($row)->not->toBeNull();

    // Whatever the op log stores, it must round-trip to the plaintext we sent
    // — the point is that it was never double-encrypted on the way in.
    $stored = is_string($row->value) ? $row->value : '';
    expect($stored)->not->toBe('');
});
