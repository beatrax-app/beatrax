<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

// The header counts every skip in the window and the table draws the 50 most
// recent, so a reader debugging sync saw "616 skipped ops" above a list of 50
// with nothing saying the other 566 existed. The rows it leaves out are not
// the rows it draws — on the machine this was found, the visible 50 were all
// tax_transaction_tags while the remainder held transactions — so the missing
// ones can be the whole reason the reader came.
//
// Named apart from the fixtures in SyncHealthPageTest: both files load into
// one process and a second global of the same name is a fatal.
function skippedCapUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function seedSkippedRows(DatabaseManager $db, int $userId, int $count, string $table = 'transactions'): void
{
    $now = CarbonImmutable::now();
    $rows = [];
    foreach (range(1, $count) as $n) {
        $rows[] = [
            'user_id' => $userId,
            'table_name' => $table,
            'pk' => (string) $n,
            'device_id' => 'device-test',
            'reason' => 'forged_signature',
            'created_at' => $now->subSeconds($n)->toDateTimeString(),
        ];
    }
    $db->connection()->table('op_log_quarantine')->insert($rows);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('says how many of the skipped ops it is actually showing', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = skippedCapUser('sync-cap-reader');
    seedSkippedRows($db, $user->id, 63);

    $html = $this->actingAs($user)->get('/dev/sync-health')->assertOk()->getContent();

    expect($html)->toContain('sync-health-truncated')
        ->and($html)->toContain('Showing the 50 most recent of 63.');
});

it('stays quiet when the table already holds every skipped op', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = skippedCapUser('sync-uncapped-reader');
    seedSkippedRows($db, $user->id, 4);

    $html = $this->actingAs($user)->get('/dev/sync-health')->assertOk()->getContent();

    expect($html)->not->toContain('sync-health-truncated');
});
