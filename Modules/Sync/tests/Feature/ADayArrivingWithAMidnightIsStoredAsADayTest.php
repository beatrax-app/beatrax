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

// A peer whose own column still carries the midnight its cast stamped on sends
// the long shape, and the gate accepts it on purpose — refusing it cost six of
// seven goals a sync. What nothing did was trim it before the write, so the
// long shape landed in a DATE column: '2026-09-16 00:00:00' is not
// <= '2026-09-16' in SQLite, and the row falls out of every window the short
// ones stay in. A migration repaired those rows once; nothing stops a peer
// writing them again.
//
// Named apart from APeerCannotLandADayTheCalendarDoesNotHaveTest: both load
// into one process and a second global of the same name is a fatal.

function midnightDayUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function midnightDayGoal(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('goals')->insertGetId([
        'user_id' => $userId,
        'name' => 'Midnight fixture',
        'target_minor' => 250000,
        'target_currency' => 'EUR',
        'start_date' => '2026-06-01',
        'target_date' => '2027-06-01',
        'status' => 'active',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function midnightDayEntry(string $table, int $pk, string $field, string $value, int $userId): OpLogEntry
{
    $fields = [
        'table' => $table,
        'pk' => $pk,
        'field' => $field,
        'value' => json_encode($value, JSON_THROW_ON_ERROR),
        'hlcL' => 2000,
        'hlcC' => 0,
        'deviceId' => 'device-midnight',
        'opType' => OpType::Set,
        'userId' => $userId,
    ];

    $stub = new OpLogEntry(...[...$fields, 'signature' => '']);

    return new OpLogEntry(...[...$fields, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->user = midnightDayUser('midnight-owner');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->goal = midnightDayGoal($db, (int) $this->user->id);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-midnight' => bin2hex(sodium_crypto_sign_publickey($keypair))];
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('stores a day a peer sent with its midnight on as the day alone', function (string $supplied): void {
    $entry = midnightDayEntry('goals', $this->goal, 'target_date', $supplied, (int) $this->user->id);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay([$entry], (int) $this->user->id);

    expect($this->db->connection()->table('goals')->where('id', $this->goal)->value('target_date'))
        ->toBe('2027-08-14');
})->with([
    'a midnight the peer cast stamped on' => ['2027-08-14 00:00:00'],
    'the same day in ISO form' => ['2027-08-14T00:00:00'],
    'a day that arrived padded' => [' 2027-08-14 '],
]);

it('leaves the stored day inside a window its own last day bounds', function (): void {
    $entry = midnightDayEntry('goals', $this->goal, 'target_date', '2027-08-14 00:00:00', (int) $this->user->id);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay([$entry], (int) $this->user->id);

    // The comparison every upper-bounded window in the tree makes. A row the
    // peer dated on the window's last day has to survive it.
    $inWindow = $this->db->connection()->table('goals')
        ->where('id', $this->goal)
        ->where('target_date', '<=', '2027-08-14')
        ->exists();

    expect($inWindow)->toBeTrue();
});
