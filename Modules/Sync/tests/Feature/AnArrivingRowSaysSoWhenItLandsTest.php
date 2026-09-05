<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Public\Events\PeerRowsApplied;

uses(RefreshDatabase::class);

// A merge writes through the query builder, so no model event fires and no
// domain event is raised: every listener maintaining derived state or a
// cross-row rule was skipped on arrival. PeerRowsApplied is what an arriving
// row says for itself, and it says it after the merge transaction commits.

function arrivingRowUser(): User
{
    return User::query()->create([
        'username' => 'arriving-row-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function arrivingRowCategory(DatabaseManager $db, int $userId, string $name): int
{
    return (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'created_at' => '2026-09-01 09:00:00',
        'updated_at' => '2026-09-01 09:00:00',
    ]);
}

function arrivingRowEntry(string $field, ?string $value, int|string $pk, OpType $opType, int $hlcC, int $userId, string $secretKey): OpLogEntry
{
    $unsigned = new OpLogEntry(
        table: 'categories',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: 8000,
        hlcC: $hlcC,
        deviceId: 'peer-device',
        opType: $opType,
        signature: '',
        userId: $userId,
    );

    return new OpLogEntry(
        table: 'categories',
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: 8000,
        hlcC: $hlcC,
        deviceId: 'peer-device',
        opType: $opType,
        signature: (new DeviceKeySigner)->sign($unsigned->signingPayload(), $secretKey),
        userId: $userId,
    );
}

/**
 * @param  list<OpLogEntry>  $entries
 * @return list<PeerRowsApplied>
 */
function arrivingRowAnnouncements(DatabaseManager $db, array $entries, int $userId, string $publicKeyHex): array
{
    $heard = [];

    // A closure listener rather than Event::fake(): the replayer resolves the
    // dispatcher per dispatch, and this keeps every real listener running so
    // the wiring under test is the one production uses.
    Event::listen(PeerRowsApplied::class, function (PeerRowsApplied $event) use (&$heard): void {
        $heard[] = $event;
    });

    (new OpLogReplayer($db, ['peer-device' => $publicKeyHex]))->replay($entries, $userId);

    return $heard;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-05 10:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->user = arrivingRowUser();
    $this->actingAs($this->user);

    $keypair = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($keypair));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('names the row a create landed under', function (): void {
    $userId = (int) $this->user->id;
    $newPk = 940001;

    $heard = arrivingRowAnnouncements($this->db, [
        arrivingRowEntry('name', json_encode('Utilities', JSON_THROW_ON_ERROR), $newPk, OpType::CreateRow, 1, $userId, $this->secretKey),
        arrivingRowEntry('slug', json_encode('utilities-peer', JSON_THROW_ON_ERROR), $newPk, OpType::CreateRow, 2, $userId, $this->secretKey),
        arrivingRowEntry('kind', json_encode('expense', JSON_THROW_ON_ERROR), $newPk, OpType::CreateRow, 3, $userId, $this->secretKey),
    ], $userId, $this->publicKeyHex);

    expect($this->db->connection()->table('categories')->where('id', $newPk)->value('name'))->toBe('Utilities')
        ->and($heard)->toHaveCount(1)
        ->and($heard[0]->userId)->toBe($userId)
        ->and($heard[0]->created)->toBe(['categories' => [$newPk]])
        ->and($heard[0]->updated)->toBe([])
        ->and($heard[0]->deleted)->toBe([]);
});

it('names the row a field merge rewrote', function (): void {
    $userId = (int) $this->user->id;
    $categoryId = arrivingRowCategory($this->db, $userId, 'Groceries');

    $heard = arrivingRowAnnouncements($this->db, [
        arrivingRowEntry('name', json_encode('Boodschappen', JSON_THROW_ON_ERROR), $categoryId, OpType::Set, 1, $userId, $this->secretKey),
    ], $userId, $this->publicKeyHex);

    expect($this->db->connection()->table('categories')->where('id', $categoryId)->value('name'))->toBe('Boodschappen')
        ->and($heard)->toHaveCount(1)
        ->and($heard[0]->updated)->toBe(['categories' => [$categoryId]])
        ->and($heard[0]->created)->toBe([]);
});

it('names the row a tombstone removed', function (): void {
    $userId = (int) $this->user->id;
    $categoryId = arrivingRowCategory($this->db, $userId, 'Hobbies');

    $heard = arrivingRowAnnouncements($this->db, [
        arrivingRowEntry(OpLogWriter::TOMBSTONE_FIELD, null, $categoryId, OpType::DeleteTombstone, 1, $userId, $this->secretKey),
    ], $userId, $this->publicKeyHex);

    expect($this->db->connection()->table('categories')->where('id', $categoryId)->count())->toBe(0)
        ->and($heard)->toHaveCount(1)
        ->and($heard[0]->deleted)->toBe(['categories' => [$categoryId]])
        ->and($heard[0]->deletedFrom('categories'))->toBe([$categoryId])
        ->and($heard[0]->touchedAnyOf(['categories']))->toBeTrue()
        ->and($heard[0]->touchedAnyOf(['recurring_series']))->toBeFalse();
});

it('says nothing when a replay applied nothing', function (): void {
    expect(arrivingRowAnnouncements($this->db, [], (int) $this->user->id, $this->publicKeyHex))->toBe([]);
});

it('stands when a listener on the announcement throws', function (): void {
    $userId = (int) $this->user->id;
    $categoryId = arrivingRowCategory($this->db, $userId, 'Insurance');

    Event::listen(PeerRowsApplied::class, function (): void {
        throw new RuntimeException('a listener that keeps derived state fell over');
    });

    // The merge has committed by the time the announcement goes out, so the
    // rows are stored whatever the listener does. Propagating would stop a
    // catch-up mid-batch over work that is already durable.
    (new OpLogReplayer($this->db, ['peer-device' => $this->publicKeyHex]))->replay([
        arrivingRowEntry('name', json_encode('Verzekering', JSON_THROW_ON_ERROR), $categoryId, OpType::Set, 1, $userId, $this->secretKey),
    ], $userId);

    expect($this->db->connection()->table('categories')->where('id', $categoryId)->value('name'))->toBe('Verzekering');
});
