<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SystemAlertWriter;

uses(RefreshDatabase::class);

// acknowledgeForUser() stamped by id alone. Every caller resolves the row
// through SystemAlertQuery::visibleTo() first, so nothing reached it wrongly --
// but the name says ForUser and the predicate did not, which is the shape a
// second caller walks into.

function ansUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'ans-'.$suffix,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function ansAlert(DatabaseManager $db, ?int $userId): int
{
    return (int) $db->connection()->table('system_alerts')->insertGetId([
        'user_id' => $userId,
        'kind' => 'update_available',
        'severity' => 'info',
        'message' => 'fixture',
        'metadata' => json_encode(['latestVersion' => '9.9.9'], JSON_THROW_ON_ERROR),
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);
}

function ansStamp(DatabaseManager $db, int $alertId): ?string
{
    /** @var object{acknowledged_at: ?string}|null $row */
    $row = $db->connection()->table('system_alerts')->where('id', $alertId)->first();

    return $row?->acknowledged_at;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    /** @var SystemAlertWriter $writer */
    $writer = $this->app->make(SystemAlertWriter::class);
    $this->writer = $writer;

    $this->now = CarbonImmutable::parse('2026-05-21 09:00:00');
});

it('leaves another reader row alone even when handed its id', function (): void {
    $owner = ansUser('owner');
    $other = ansUser('other');

    $alertId = ansAlert($this->db, $owner->id);

    expect($this->writer->acknowledgeForUser($alertId, $other->id, $this->now))->toBeFalse();

    expect(ansStamp($this->db, $alertId))->toBeNull();
});

// The positive control. Without it a writer that stamped nothing at all would
// satisfy the case above and break every banner in the product.
it('stamps the row it was named for', function (): void {
    $owner = ansUser('owner');

    $alertId = ansAlert($this->db, $owner->id);

    expect($this->writer->acknowledgeForUser($alertId, $owner->id, $this->now))->toBeTrue();

    expect(ansStamp($this->db, $alertId))->not->toBeNull();
});

// A system-wide row belongs to nobody, and null is what names it. Passing a
// user id must not stamp it, and passing null must.
it('keeps a whole-household row apart from one reader answer', function (): void {
    $reader = ansUser('reader');

    $alertId = ansAlert($this->db, null);

    expect($this->writer->acknowledgeForUser($alertId, $reader->id, $this->now))->toBeFalse();
    expect(ansStamp($this->db, $alertId))->toBeNull();

    expect($this->writer->acknowledgeForUser($alertId, null, $this->now))->toBeTrue();
    expect(ansStamp($this->db, $alertId))->not->toBeNull();
});

it('answers false a second time, whoever asks', function (): void {
    $owner = ansUser('owner');

    $alertId = ansAlert($this->db, $owner->id);

    expect($this->writer->acknowledgeForUser($alertId, $owner->id, $this->now))->toBeTrue();
    expect($this->writer->acknowledgeForUser($alertId, $owner->id, $this->now))->toBeFalse();
});
