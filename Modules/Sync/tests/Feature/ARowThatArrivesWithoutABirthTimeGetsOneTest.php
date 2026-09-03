<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// Six envelope moves on a paired desktop held created_at NULL. They arrived
// from a build whose capture did not name the column, the applier wrote what
// it was given, and nothing afterwards gave the row a date. The lists that
// order by created_at put a null LAST however new the row is, so those moves
// sat under moves a week older — and past the ten-row limit they are dropped
// from the page altogether. The device that re-captures such a row sends the
// null on, so it spreads.

function birthTimeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $fields
 * @return list<OpLogEntry>
 */
function birthTimeCreate(string $table, int $pk, array $fields, int $userId, int $hlcL): array
{
    $entries = [];
    $tick = 0;

    foreach ($fields as $field => $value) {
        $common = [
            'table' => $table,
            'pk' => $pk,
            'field' => $field,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'hlcL' => $hlcL,
            'hlcC' => $tick++,
            'deviceId' => 'device-birthtime',
            'opType' => OpType::CreateRow,
            'userId' => $userId,
        ];

        $stub = new OpLogEntry(...[...$common, 'signature' => '']);
        $entries[] = new OpLogEntry(...[...$common, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
    }

    return $entries;
}

beforeEach(function (): void {
    $this->user = birthTimeUser('birthtime-owner');

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-birthtime' => bin2hex(sodium_crypto_sign_publickey($keypair))];

    /** @var DatabaseManager $db */
    $this->db = app(DatabaseManager::class);

    // 2026-09-02 21:07:48 UTC, the moment the real ops carried.
    $this->hlcL = 1788376068000;
});

it('gives a row whose create names no birth time the one its op carries', function (): void {
    $entries = birthTimeCreate('goals', 4242, [
        'user_id' => (int) $this->user->id,
        'name' => 'Zonder datum',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-01',
        'target_date' => '2027-09-01',
        'status' => 'active',
    ], (int) $this->user->id, $this->hlcL);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay($entries, (int) $this->user->id);

    $stored = $this->db->connection()->table('goals')->where('id', 4242)->first();

    expect($stored?->created_at)->not->toBeNull()
        ->and($stored?->updated_at)->not->toBeNull()
        ->and((string) $stored?->created_at)->toContain('2026-09-02');
});

it('gives a row whose create names a NULL birth time one as well', function (): void {
    $entries = birthTimeCreate('goals', 4243, [
        'user_id' => (int) $this->user->id,
        'name' => 'Null datum',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-01',
        'target_date' => '2027-09-01',
        'status' => 'active',
        'created_at' => null,
        'updated_at' => null,
    ], (int) $this->user->id, $this->hlcL);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay($entries, (int) $this->user->id);

    expect($this->db->connection()->table('goals')->where('id', 4243)->value('created_at'))->not->toBeNull();
});

// The date the peer knew beats the one this device can infer.
it('keeps the birth time the create does carry', function (): void {
    $entries = birthTimeCreate('goals', 4244, [
        'user_id' => (int) $this->user->id,
        'name' => 'Eigen datum',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-01',
        'target_date' => '2027-09-01',
        'status' => 'active',
        'created_at' => '2026-06-14 10:00:00',
        'updated_at' => '2026-06-14 10:00:00',
    ], (int) $this->user->id, $this->hlcL);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay($entries, (int) $this->user->id);

    expect((string) $this->db->connection()->table('goals')->where('id', 4244)->value('created_at'))
        ->toContain('2026-06-14');
});
