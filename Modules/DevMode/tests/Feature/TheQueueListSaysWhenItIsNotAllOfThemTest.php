<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

// The count tiles hold every row the queue has and QueueRowLoader takes the
// newest ROW_LIMIT of them, so 795 failed jobs sat above a list of 100 with
// nothing saying the other 695 were there. Same shape as the skipped-ops list
// on /dev/sync-health; both now say what they are not showing, through one
// shared string.
function queueCapUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function seedFailedJobs(DatabaseManager $db, int $count): void
{
    $now = CarbonImmutable::now();
    $rows = [];
    foreach (range(1, $count) as $n) {
        $rows[] = [
            'uuid' => 'uuid-'.$n,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Thing'.$n]),
            'exception' => 'boom',
            'failed_at' => $now->subSeconds($n)->toDateTimeString(),
        ];
    }
    $db->connection()->table('failed_jobs')->insert($rows);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('says how many of the failed jobs it is actually showing', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = queueCapUser('queue-cap-reader');
    seedFailedJobs($db, 137);

    $html = $this->actingAs($user)->get('/dev/queue/failed')->assertOk()->getContent();

    expect($html)->toContain('queue-truncated')
        ->and($html)->toContain('Showing the 100 most recent of 137.');
});

it('stays quiet when the table already holds every failed job', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = queueCapUser('queue-uncapped-reader');
    seedFailedJobs($db, 3);

    $html = $this->actingAs($user)->get('/dev/queue/failed')->assertOk()->getContent();

    expect($html)->not->toContain('queue-truncated');
});
