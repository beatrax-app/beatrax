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

// Found on a paired desktop and phone. Both made an envelope move while apart,
// both took id 9, and the arriving create was discarded as an idempotent
// replay: the two devices then disagreed about money with an empty quarantine
// on each side. Nine covered tables mint an autoincrement with no natural key
// to tell two such rows apart.

function pkcUser(string $username): User
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
function pkcCreate(string $table, int $pk, array $fields, int $userId): array
{
    $entries = [];
    $tick = 0;

    foreach ($fields as $field => $value) {
        $common = [
            'table' => $table,
            'pk' => $pk,
            'field' => $field,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'hlcL' => 5000,
            'hlcC' => $tick++,
            'deviceId' => 'device-pkc-peer',
            'opType' => OpType::CreateRow,
            'userId' => $userId,
        ];

        $stub = new OpLogEntry(...[...$common, 'signature' => '']);
        $entries[] = new OpLogEntry(...[...$common, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
    }

    return $entries;
}

/**
 * @return list<string>
 */
function pkcReasons(DatabaseManager $db): array
{
    return $db->connection()->table('op_log_quarantine')->pluck('reason')->all();
}

beforeEach(function (): void {
    $this->user = pkcUser('pkc-owner');

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-pkc-peer' => bin2hex(sodium_crypto_sign_publickey($keypair))];

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $db->connection()->table('goals')->insert([
        'id' => 1,
        'user_id' => $this->user->id,
        'name' => 'Nieuwe fiets',
        'target_minor' => 125000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-02',
        'target_date' => '2027-06-30',
        'status' => 'active',
        'created_at' => '2026-09-02 21:07:48',
        'updated_at' => '2026-09-02 21:07:48',
    ]);
});

/**
 * @return array<string, mixed>
 */
function pkcPeerGoal(int $userId): array
{
    return [
        'user_id' => $userId,
        'name' => 'Zonnepanelen',
        'target_minor' => 900000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-02',
        'target_date' => '2028-01-31',
        'status' => 'active',
        'created_at' => '2026-09-02 22:55:05',
        'updated_at' => '2026-09-02 22:55:05',
    ];
}

it('quarantines a create naming an id a different row already holds', function (): void {
    $entries = pkcCreate('goals', 1, pkcPeerGoal((int) $this->user->id), (int) $this->user->id);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay($entries, (int) $this->user->id);

    $stored = $this->db->connection()->table('goals')->where('id', 1)->first();

    expect($stored?->name)->toBe('Nieuwe fiets')
        ->and($stored?->target_minor)->toBe(125000)
        ->and(pkcReasons($this->db))->toContain('primary_key_collision');
});

it('leaves the peer row out rather than writing a blend of the two', function (): void {
    $entries = pkcCreate('goals', 1, pkcPeerGoal((int) $this->user->id), (int) $this->user->id);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay($entries, (int) $this->user->id);

    expect($this->db->connection()->table('goals')->count())->toBe(1)
        ->and($this->db->connection()->table('goals')->where('name', 'Zonnepanelen')->exists())->toBeFalse();
});

it('stays silent when the very same create is replayed', function (): void {
    $same = [
        'user_id' => (int) $this->user->id,
        'name' => 'Nieuwe fiets',
        'target_minor' => 125000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-02',
        'target_date' => '2027-06-30',
        'status' => 'active',
        'created_at' => '2026-09-02 21:07:48',
        'updated_at' => '2026-09-02 21:07:48',
    ];

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay(pkcCreate('goals', 1, $same, (int) $this->user->id), (int) $this->user->id);

    expect(pkcReasons($this->db))->toBe([]);
});

// The case a whole-payload comparison gets wrong: thirty-two of thirty-six
// differences on a real device were this one. The create is stale because the
// row moved on, not because it belongs to another row.
it('stays silent when the create is replayed after its own row was edited', function (): void {
    $this->db->connection()->table('goals')->where('id', 1)->update([
        'name' => 'Nieuwe fiets (2027)',
        'target_minor' => 150000,
        'status' => 'paused',
        'updated_at' => '2026-09-02 23:40:00',
    ]);

    $original = [
        'user_id' => (int) $this->user->id,
        'name' => 'Nieuwe fiets',
        'target_minor' => 125000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-02',
        'target_date' => '2027-06-30',
        'status' => 'active',
        'created_at' => '2026-09-02 21:07:48',
        'updated_at' => '2026-09-02 21:07:48',
    ];

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay(pkcCreate('goals', 1, $original, (int) $this->user->id), (int) $this->user->id);

    expect(pkcReasons($this->db))->toBe([])
        ->and($this->db->connection()->table('goals')->where('id', 1)->value('name'))->toBe('Nieuwe fiets (2027)');
});

// The arm this check must not take over: the row IS here, under an id only the
// peer ever used, and the alias is what keeps every child naming it resolvable.
it('records an alias rather than a collision when the row is here under another id', function (): void {
    $local = $this->db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $this->user->id,
        'name' => 'Studiekosten',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => '2026-09-02 21:07:48',
        'updated_at' => '2026-09-02 21:07:48',
    ]);

    $entries = pkcCreate('tax_deduction_categories', 109, [
        'user_id' => (int) $this->user->id,
        'name' => 'Studiekosten',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => '2026-09-02 22:55:05',
        'updated_at' => '2026-09-02 22:55:05',
    ], (int) $this->user->id);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay($entries, (int) $this->user->id);

    $alias = $this->db->connection()->table('op_log_row_aliases')
        ->where('table_name', 'tax_deduction_categories')->where('remote_id', '109')->value('local_id');

    expect($alias)->toBe((string) $local)
        ->and(pkcReasons($this->db))->toBe([]);
});
