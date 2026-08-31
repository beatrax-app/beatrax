<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// Every supplied date in this tree is refused at the boundary it crosses. The
// op-log applier was the exception: a validly signed peer wrote a wire value
// straight into a DATE column, so '2027-02-29' landed and only the model cast
// objected — on the way back OUT, to a reader who had done nothing wrong. The
// entry is signed by the user's own device and passes every other gate; what
// was unguarded is the value.
//
// Named apart from the fixtures in CrossUserReferenceGuardTest: both files load
// into one process and a second global of the same name is a fatal.

function impDateUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function impDateGoal(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('goals')->insertGetId([
        'user_id' => $userId,
        'name' => 'Impossible date fixture',
        'target_minor' => 250000,
        'target_currency' => 'EUR',
        'start_date' => '2026-06-01',
        'target_date' => '2027-06-01',
        'status' => 'active',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function impDateEntry(string $table, int $pk, string $field, string $value, int $userId, OpType $opType): OpLogEntry
{
    $fields = [
        'table' => $table,
        'pk' => $pk,
        'field' => $field,
        // The wire carries JSON: LwwPerFieldStrategy json_decodes it, so a bare
        // date string is a syntax error long before any gate reads it.
        'value' => json_encode($value, JSON_THROW_ON_ERROR),
        'hlcL' => 1000,
        'hlcC' => 0,
        'deviceId' => 'device-impdate',
        'opType' => $opType,
        'userId' => $userId,
    ];

    $stub = new OpLogEntry(...[...$fields, 'signature' => '']);

    return new OpLogEntry(...[...$fields, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
}

function impDateReason(DatabaseManager $db, string $table): ?string
{
    $reason = $db->connection()->table('op_log_quarantine')->where('table_name', $table)->value('reason');

    return is_string($reason) ? $reason : null;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->user = impDateUser('impdate-owner');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->goal = impDateGoal($db, (int) $this->user->id);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-impdate' => bin2hex(sodium_crypto_sign_publickey($keypair))];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('refuses a Set that lands a day the calendar does not have', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // 2027 is not a leap year, so this is a day nobody can have meant.
    $entry = impDateEntry('goals', $this->goal, 'target_date', '2027-02-29', (int) $this->user->id, OpType::Set);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->user->id);

    $stored = $db->connection()->table('goals')->where('id', $this->goal)->value('target_date');

    expect($stored)->toBe('2027-06-01', 'the column must still hold the day it had')
        ->and(impDateReason($db, 'goals'))->toBe('impossible_date');
});

it('refuses a partial day rather than rolling it forward into one nobody meant', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // PHP's parsers all read this as a year and invent the rest of it.
    $entry = impDateEntry('goals', $this->goal, 'target_date', '2027', (int) $this->user->id, OpType::Set);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->user->id);

    expect($db->connection()->table('goals')->where('id', $this->goal)->value('target_date'))->toBe('2027-06-01')
        ->and(impDateReason($db, 'goals'))->toBe('impossible_date');
});

it('still applies a real day, so the gate is not simply refusing everything', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $entry = impDateEntry('goals', $this->goal, 'target_date', '2028-02-29', (int) $this->user->id, OpType::Set);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->user->id);

    // 2028 IS a leap year: the same 29 February, and this one is a real day.
    expect($db->connection()->table('goals')->where('id', $this->goal)->value('target_date'))->toBe('2028-02-29')
        ->and(impDateReason($db, 'goals'))->toBeNull();
});

it('leaves a column that is not a DATE alone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // The gate reads the schema, so a free-text column carrying something
    // date-shaped is none of its business.
    $entry = impDateEntry('goals', $this->goal, 'name', '2027-02-29', (int) $this->user->id, OpType::Set);

    (new OpLogReplayer($db, $this->deviceKeys))->replay([$entry], (int) $this->user->id);

    expect($db->connection()->table('goals')->where('id', $this->goal)->value('name'))->toBe('2027-02-29')
        ->and(impDateReason($db, 'goals'))->toBeNull();
});
