<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\Job;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;

uses(RefreshDatabase::class);

/*
 * EmailScanServiceProvider::registerJobFailedListener invariant.
 *
 * The boot-time JobFailed listener flips inbox_scan_state.status to
 * 'error' when a BackfillInboxJob or IncrementalScanJob exhausts the
 * worker's retry budget. The listener extracts inboxId from the
 * serialised job payload via a defensive regex; this test asserts:
 *
 *  1. A JobFailed event carrying an IncrementalScanJob payload with a
 *     valid inbox_id flips the row's status to 'error' and stamps the
 *     truncated exception message.
 *  2. A JobFailed event carrying an unrelated job name (e.g. some
 *     other module's job) leaves the row unchanged.
 *  3. A JobFailed event carrying a malformed payload (no inboxId)
 *     leaves the row unchanged (no throw, no status flip).
 *
 * The dispatcher subscription path is reached by binding the real
 * EmailScanServiceProvider via the package boot — no replay or fake
 * is needed; firing JobFailed via the dispatcher invokes the
 * listener.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'email' => 'failed-listener@example.com',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $this->inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $this->user->id,
        'provider' => 'gmail',
        'email' => 'failed-listener@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $this->user->id,
        'inbox_id' => $this->inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
});

/**
 * Build a JobFailed event with a synthesised payload that mirrors
 * what Laravel's queue serialiser produces for a queued job.
 */
function buildJobFailedEvent(string $jobName, ?int $inboxId, string $exceptionMessage): JobFailed
{
    $serialisedCommand = $inboxId === null
        ? 'O:42:"SomeOtherJob":0:{}'
        : 'O:60:"Modules\\EmailScan\\Internal\\Jobs\\IncrementalScanJob":1:{s:21:"'."\0".'*'."\0".'inboxId";i:'.$inboxId.';}';

    $job = new class($jobName, $serialisedCommand) extends Job
    {
        public function __construct(
            private readonly string $jobName,
            private readonly string $serialisedCommand,
        ) {}

        public function resolveName(): string
        {
            return $this->jobName;
        }

        public function payload(): array
        {
            return [
                'data' => [
                    'command' => $this->serialisedCommand,
                ],
            ];
        }

        public function getJobId(): string
        {
            return 'fake-job-id';
        }

        public function getRawBody(): string
        {
            return '';
        }
    };

    return new JobFailed(
        'sync',
        $job,
        new RuntimeException($exceptionMessage),
    );
}

it('flips inbox_scan_state.status to error when an IncrementalScanJob JobFailed event fires', function (): void {
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    $event = buildJobFailedEvent(
        IncrementalScanJob::class,
        $this->inboxId,
        'Synthetic provider blew up after exhausting retries.',
    );

    $events->dispatch($event);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $this->inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'error_message']);

    expect($row)->not->toBeNull();
    expect($row->status)->toBe('error');
    expect((string) $row->error_message)->toContain('Synthetic provider blew up');
});

it('ignores JobFailed events for unrelated jobs (other modules)', function (): void {
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    $event = buildJobFailedEvent(
        'Modules\\Chains\\Internal\\Jobs\\ResolveChainLinksJob',
        $this->inboxId,
        'unrelated failure',
    );

    $events->dispatch($event);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $this->inboxId)
        ->where('folder', 'INBOX')
        ->first(['status']);

    expect($row)->not->toBeNull();
    expect($row->status)->toBe('idle');
});

it('ignores JobFailed events with an unparseable payload (no inboxId)', function (): void {
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    $event = buildJobFailedEvent(
        IncrementalScanJob::class,
        null,
        'unparseable payload',
    );

    $events->dispatch($event);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $this->inboxId)
        ->where('folder', 'INBOX')
        ->first(['status']);

    expect($row)->not->toBeNull();
    expect($row->status)->toBe('idle');
});
