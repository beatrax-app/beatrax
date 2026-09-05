<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// Categorization rules are device-local: a peer never receives one, so the
// device holding a rule is the only one that can deactivate it. The merge
// deletes the referent with the query builder — no model event — and raises no
// EntityMutated, so both existing arms of the listener were skipped and a rule
// went on matching against an id that no longer names anything.

function peerDeletedReferentUser(): User
{
    return User::query()->create([
        'username' => 'peer-referent-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function peerDeletedReferentCounterparty(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => 'albert-heijn-'.bin2hex(random_bytes(4)),
        'display_name' => 'Albert Heijn',
        'created_at' => '2026-09-01 09:00:00',
        'updated_at' => '2026-09-01 09:00:00',
    ]);
}

/**
 * A rule whose single action names $referentId through the opaque JSON payload
 * the schema carries no foreign key for.
 */
function peerDeletedReferentRule(DatabaseManager $db, int $userId, string $actionType, string $payloadKey, int $referentId): int
{
    $ruleId = (int) $db->connection()->table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'priority' => 0,
        'combinator' => 'all',
        'hits_count' => 0,
        'active' => true,
        'created_at' => '2026-09-01 09:00:00',
        'updated_at' => '2026-09-01 09:00:00',
    ]);

    $db->connection()->table('rule_actions')->insert([
        'rule_id' => $ruleId,
        'position' => 0,
        'type' => $actionType,
        'payload' => json_encode([$payloadKey => $referentId], JSON_THROW_ON_ERROR),
        'created_at' => '2026-09-01 09:00:00',
        'updated_at' => '2026-09-01 09:00:00',
    ]);

    return $ruleId;
}

/**
 * @param  array<string, string>  $deviceKeys
 */
function peerDeletedReferentReplay(DatabaseManager $db, array $deviceKeys, string $secretKey, string $table, int $pk, int $userId): void
{
    $unsigned = new OpLogEntry(
        table: $table,
        pk: $pk,
        field: OpLogWriter::TOMBSTONE_FIELD,
        value: null,
        hlcL: 7000,
        hlcC: 0,
        deviceId: 'peer-device',
        opType: OpType::DeleteTombstone,
        signature: '',
        userId: $userId,
    );

    $tombstone = new OpLogEntry(
        table: $table,
        pk: $pk,
        field: OpLogWriter::TOMBSTONE_FIELD,
        value: null,
        hlcL: 7000,
        hlcC: 0,
        deviceId: 'peer-device',
        opType: OpType::DeleteTombstone,
        signature: (new DeviceKeySigner)->sign($unsigned->signingPayload(), $secretKey),
        userId: $userId,
    );

    (new OpLogReplayer($db, $deviceKeys))->replay([$tombstone], $userId);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-05 10:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->user = peerDeletedReferentUser();
    $this->actingAs($this->user);

    $keypair = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    $this->deviceKeys = ['peer-device' => bin2hex(sodium_crypto_sign_publickey($keypair))];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('deactivates a rule pointing at a counterparty a peer deleted', function (): void {
    $userId = (int) $this->user->id;
    $counterpartyId = peerDeletedReferentCounterparty($this->db, $userId);
    $ruleId = peerDeletedReferentRule($this->db, $userId, 'counterparty', 'counterparty_id', $counterpartyId);

    peerDeletedReferentReplay($this->db, $this->deviceKeys, $this->secretKey, 'counterparties', $counterpartyId, $userId);

    expect($this->db->connection()->table('counterparties')->where('id', $counterpartyId)->count())->toBe(0)
        ->and((int) $this->db->connection()->table('categorization_rules')->where('id', $ruleId)->value('active'))->toBe(0);
});

it('deactivates a rule pointing at a category a peer deleted', function (): void {
    $userId = (int) $this->user->id;

    $categoryId = (int) $this->db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Groceries',
        'slug' => 'groceries-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'created_at' => '2026-09-01 09:00:00',
        'updated_at' => '2026-09-01 09:00:00',
    ]);

    $ruleId = peerDeletedReferentRule($this->db, $userId, 'category', 'category_id', $categoryId);

    peerDeletedReferentReplay($this->db, $this->deviceKeys, $this->secretKey, 'categories', $categoryId, $userId);

    expect($this->db->connection()->table('categories')->where('id', $categoryId)->count())->toBe(0)
        ->and((int) $this->db->connection()->table('categorization_rules')->where('id', $ruleId)->value('active'))->toBe(0);
});

it('leaves a rule naming a different referent alone', function (): void {
    $userId = (int) $this->user->id;
    $deleted = peerDeletedReferentCounterparty($this->db, $userId);
    $survivor = peerDeletedReferentCounterparty($this->db, $userId);
    $ruleId = peerDeletedReferentRule($this->db, $userId, 'counterparty', 'counterparty_id', $survivor);

    peerDeletedReferentReplay($this->db, $this->deviceKeys, $this->secretKey, 'counterparties', $deleted, $userId);

    expect($this->db->connection()->table('counterparties')->where('id', $survivor)->count())->toBe(1)
        ->and((int) $this->db->connection()->table('categorization_rules')->where('id', $ruleId)->value('active'))->toBe(1);
});
