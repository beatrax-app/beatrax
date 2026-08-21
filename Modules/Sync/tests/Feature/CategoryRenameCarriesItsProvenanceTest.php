<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// `name` and `name_is_default` are one fact split over two columns. A peer
// that replayed the rename without the flag would keep translating the slug
// over the top of the user's own words, so the registry has to carry both.

// $value is the JSON form an op carries on the wire, which is what the LWW
// strategy decodes.
function provenanceSignedSet(string $field, string $value, int $pk, int $userId, string $secretKey, int $hlcL): OpLogEntry
{
    $draft = new OpLogEntry(
        table: 'categories',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: 'device-peer',
        opType: OpType::Set,
        signature: '',
        userId: $userId,
    );

    return new OpLogEntry(
        table: 'categories',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: 'device-peer',
        opType: OpType::Set,
        signature: (new DeviceKeySigner)->sign($draft->signingPayload(), $secretKey),
        userId: $userId,
    );
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'sync-cat-provenance',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'name_is_default' => true,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    $this->publicKey = sodium_crypto_sign_publickey($keypair);
});

it('replays a peer rename together with the flag that stops the translation', function (): void {
    app()->setLocale('nl');

    /** @var Category $before */
    $before = Category::withoutGlobalScopes()->findOrFail($this->categoryId);
    expect($before->display_name)->toBe('Boodschappen');

    $userId = (int) $this->user->id;
    $entries = [
        provenanceSignedSet('name', '"Supermarkt"', (int) $this->categoryId, $userId, $this->secretKey, 2000),
        provenanceSignedSet('name_is_default', 'false', (int) $this->categoryId, $userId, $this->secretKey, 2001),
    ];

    $replayer = new OpLogReplayer($this->db, ['device-peer' => bin2hex($this->publicKey)]);
    $replayer->replay($entries, $userId);

    /** @var Category $after */
    $after = Category::withoutGlobalScopes()->findOrFail($this->categoryId);
    expect($after->name)->toBe('Supermarkt')
        ->and($after->name_is_default)->toBeFalse()
        ->and($after->display_name)->toBe('Supermarkt');

    app()->setLocale('de');
    /** @var Category $german */
    $german = Category::withoutGlobalScopes()->findOrFail($this->categoryId);
    expect($german->display_name)->toBe('Supermarkt');
});
