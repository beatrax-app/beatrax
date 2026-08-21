<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;

beforeEach(function (): void {
    // Freeze the clock so the duration-cutoff arithmetic is deterministic.
    $this->frozenNow = CarbonImmutable::parse('2026-05-20 09:00:00');
    $clock = $this->createStub(Clock::class);
    $clock->method('now')->willReturn($this->frozenNow);
    $this->app->instance(Clock::class, $clock);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $seedRow = function (int $daysAgo, string $queueName) use ($db): void {
        $failedAt = CarbonImmutable::parse('2026-05-20 09:00:00')->subDays($daysAgo);
        $db->connection()->table('failed_jobs')->insert([
            'uuid' => bin2hex(random_bytes(8)).'-'.$daysAgo,
            'connection' => 'database',
            'queue' => $queueName,
            'payload' => '{}',
            'exception' => 'RuntimeException: fixture',
            'failed_at' => $failedAt->toDateTimeString(),
        ]);
    };

    $seedRow(7, 'q-7');
    $seedRow(14, 'q-14');
    $seedRow(31, 'q-31');
    $seedRow(45, 'q-45');
    $seedRow(60, 'q-60');
});

it('--dry-run prints rows that would be deleted without writing', function (): void {
    $this->artisan('beatrax:failed-jobs', ['action' => 'prune', '--older-than' => '30d', '--dry-run' => true])
        ->expectsOutputToContain('Would delete 3 rows')
        ->assertSuccessful();

    $remaining = $this->db->connection()->table('failed_jobs')->count();
    expect($remaining)->toBe(5);
});

it('deletes failed_jobs rows older than the cutoff on live run', function (): void {
    $this->artisan('beatrax:failed-jobs', ['action' => 'prune', '--older-than' => '30d'])
        ->expectsOutputToContain('Removed 3 rows; 2 remaining.')
        ->assertSuccessful();

    $remaining = $this->db->connection()->table('failed_jobs')->count();
    expect($remaining)->toBe(2);

    // Each queue name carries its row's age, so the survivors name themselves.
    $queues = $this->db->connection()->table('failed_jobs')->orderBy('queue')->pluck('queue')->all();
    expect($queues)->toBe(['q-14', 'q-7']);
});

it('rejects an invalid --older-than token with exit 1 and the regex error message', function (): void {
    $this->artisan('beatrax:failed-jobs', ['action' => 'prune', '--older-than' => '30m'])
        ->expectsOutputToContain('Duration must match /^\\d+[dhw]$/')
        ->assertFailed();

    $remaining = $this->db->connection()->table('failed_jobs')->count();
    expect($remaining)->toBe(5);
});

it('rejects an unknown action with exit 1', function (): void {
    $this->artisan('beatrax:failed-jobs', ['action' => 'whatever'])
        ->expectsOutputToContain('Unknown action: whatever')
        ->assertFailed();

    $remaining = $this->db->connection()->table('failed_jobs')->count();
    expect($remaining)->toBe(5);
});

it('defaults the action argument to prune so bare `beatrax:failed-jobs` runs the prune path', function (): void {
    $this->artisan('beatrax:failed-jobs', ['--older-than' => '30d'])
        ->expectsOutputToContain('Removed 3 rows; 2 remaining.')
        ->assertSuccessful();

    $remaining = $this->db->connection()->table('failed_jobs')->count();
    expect($remaining)->toBe(2);
});
