<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Enums\AuditEvent;
use Modules\DevMode\Internal\Http\Livewire\QueueInspectorPage;
use Modules\DevMode\Internal\Queue\QueueActions;

function queueDeveloper(string $username = 'queue-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function queueNonDeveloper(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => false,
    ]);
}

function seedFailedJob(string $uuid, string $connection = 'database', string $queue = 'default', string $payload = '{"attempts":3,"data":{"command":"O:8:\"stdClass\":0:{}"}}'): int
{
    return (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => $uuid,
        'connection' => $connection,
        'queue' => $queue,
        'payload' => $payload,
        'exception' => 'RuntimeException: boom',
        'failed_at' => CarbonImmutable::now(),
    ]);
}

function seedPendingJob(string $queue = 'default'): int
{
    return (int) DB::table('jobs')->insertGetId([
        'queue' => $queue,
        'payload' => '{"data":{"command":"O:8:\"stdClass\":0:{}"}}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => CarbonImmutable::now()->getTimestamp(),
        'created_at' => CarbonImmutable::now()->getTimestamp(),
    ]);
}

function seedBatch(string $id, int $failedJobs = 0, ?int $cancelledAt = null): void
{
    DB::table('job_batches')->insert([
        'id' => $id,
        'name' => 'batch-'.$id,
        'total_jobs' => 5,
        'pending_jobs' => 5 - $failedJobs,
        'failed_jobs' => $failedJobs,
        'failed_job_ids' => json_encode([]),
        'options' => json_encode([]),
        'cancelled_at' => $cancelledAt,
        'created_at' => CarbonImmutable::now()->getTimestamp(),
        'finished_at' => null,
    ]);
}

it('redirects /dev/queue to /dev/queue/pending', function (): void {
    $user = queueDeveloper('queue-redirect');

    $response = $this->actingAs($user)->get('/dev/queue');

    $response->assertRedirect('/dev/queue/pending');
});

it('returns 200 for /dev/queue/pending|failed|batches as a developer (route param drives the tab)', function (): void {
    $user = queueDeveloper('queue-tabs');

    foreach (['pending', 'failed', 'batches'] as $tab) {
        $response = $this->actingAs($user)->get('/dev/queue/'.$tab);
        $response->assertOk();
        $response->assertSee('Queue inspector', escape: false);
    }
});

it('returns 404 for /dev/queue/* as a non-developer (EnsureDeveloperMode gate)', function (): void {
    queueDeveloper('queue-seed-for-gate');
    $user = queueNonDeveloper('queue-nondev');

    foreach (['pending', 'failed', 'batches'] as $tab) {
        $response = $this->actingAs($user)->get('/dev/queue/'.$tab);
        $response->assertNotFound();
    }
});

it('QueueActions::forgetFailed removes the failed_jobs row AND writes an audit row with action=queue.failed.forget', function (): void {
    $user = queueDeveloper('q-forget');
    $this->actingAs($user);

    $uuid = 'uuid-forget-001';
    seedFailedJob($uuid);

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();

    /** @var QueueActions $actions */
    $actions = app(QueueActions::class);
    $actions->forgetFailed($uuid);

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();

    $auditRow = DB::table('dev_mode_audit')->latest('id')->first();
    expect($auditRow)->not->toBeNull();
    expect($auditRow->description)->toBe(AuditEvent::QueueAction->value);
    $properties = json_decode((string) $auditRow->properties, true);
    expect($properties['action'] ?? null)->toBe(AuditEvent::QueueFailedForget->value);
    expect($properties['context']['uuid'] ?? null)->toBe($uuid);
});

it('QueueActions::retryFailed re-dispatches the payload AND removes the failed_jobs row AND writes an audit row', function (): void {
    $user = queueDeveloper('q-retry');
    $this->actingAs($user);

    $uuid = 'uuid-retry-001';
    seedFailedJob($uuid);

    $pendingBefore = DB::table('jobs')->count();

    /** @var QueueActions $actions */
    $actions = app(QueueActions::class);
    $actions->retryFailed($uuid);

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();
    expect(DB::table('jobs')->count())->toBe($pendingBefore + 1);

    $auditRow = DB::table('dev_mode_audit')->latest('id')->first();
    $properties = json_decode((string) $auditRow->properties, true);
    expect($properties['action'] ?? null)->toBe(AuditEvent::QueueFailedRetry->value);
});

it('QueueActions::deletePending removes the jobs row AND writes an audit row', function (): void {
    $user = queueDeveloper('q-pending-delete');
    $this->actingAs($user);

    $id = seedPendingJob();

    /** @var QueueActions $actions */
    $actions = app(QueueActions::class);
    $actions->deletePending($id);

    expect(DB::table('jobs')->where('id', $id)->exists())->toBeFalse();

    $auditRow = DB::table('dev_mode_audit')->latest('id')->first();
    $properties = json_decode((string) $auditRow->properties, true);
    expect($properties['action'] ?? null)->toBe(AuditEvent::QueuePendingDelete->value);
});

it('QueueActions::cancelBatch sets cancelled_at on the batch row AND writes an audit row', function (): void {
    $user = queueDeveloper('q-batch-cancel');
    $this->actingAs($user);

    $batchId = 'batch-cancel-001';
    seedBatch($batchId);

    /** @var QueueActions $actions */
    $actions = app(QueueActions::class);
    $actions->cancelBatch($batchId);

    $row = DB::table('job_batches')->where('id', $batchId)->first();
    expect($row)->not->toBeNull();
    expect($row->cancelled_at)->not->toBeNull();

    $auditRow = DB::table('dev_mode_audit')->latest('id')->first();
    $properties = json_decode((string) $auditRow->properties, true);
    expect($properties['action'] ?? null)->toBe(AuditEvent::QueueBatchCancel->value);
});

it('the count tiles render the live counts with the wire:poll.5s attribute', function (): void {
    $user = queueDeveloper('q-tiles');

    seedPendingJob();
    seedPendingJob();
    seedFailedJob('uuid-tile-1');
    seedFailedJob('uuid-tile-2');
    seedFailedJob('uuid-tile-3');
    seedBatch('batch-tile-1');

    $response = $this->actingAs($user)->get('/dev/queue/pending');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect(str_contains($html, 'wire:poll.5s'))->toBeTrue('wire:poll.5s attribute should be present on count tiles');

    expect($html)->toContain('data-testid="tile-pending-count">2<');
    expect($html)->toContain('data-testid="tile-failed-count">3<');
    expect($html)->toContain('data-testid="tile-batches-count">1<');
});

it('enables the Queue sidebar item (drops nav-disabled when dev.queue is registered)', function (): void {
    $user = queueDeveloper('q-sidebar');

    $response = $this->actingAs($user)->get('/dev/queue/pending');
    $html = (string) $response->getContent();

    preg_match_all('#<a\s+href="[^"]*"\s+class="side-item([^"]*)"[^>]*>[\s\S]*?Queue[\s\S]*?</a>#', $html, $matches);

    expect($matches[0])->not->toBeEmpty('Queue sidebar entry should render');
    foreach ($matches[1] as $classes) {
        expect($classes)->not->toContain('nav-disabled', 'Queue sidebar entry should NOT have nav-disabled when dev.queue is registered');
    }
});

it('the bulk-delete action dispatches triple-gate:open without performing the delete until confirmed', function (): void {
    $user = queueDeveloper('q-bulk-delete-gate');
    $this->actingAs($user);

    $uuid1 = 'uuid-bulk-1';
    $uuid2 = 'uuid-bulk-2';
    seedFailedJob($uuid1);
    seedFailedJob($uuid2);

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', [$uuid1, $uuid2])
        ->call('bulkDeleteRequest')
        ->assertDispatched('triple-gate:open');

    expect(DB::table('failed_jobs')->whereIn('uuid', [$uuid1, $uuid2])->count())->toBe(2);
});

it('the bulk-retry action takes the single-confirm path (NOT triple-gate) and retries every selected uuid', function (): void {
    $user = queueDeveloper('q-bulk-retry');
    $this->actingAs($user);

    $uuid1 = 'uuid-bulk-retry-1';
    $uuid2 = 'uuid-bulk-retry-2';
    seedFailedJob($uuid1);
    seedFailedJob($uuid2);

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', [$uuid1, $uuid2])
        ->call('bulkRetryConfirm')
        ->assertNotDispatched('triple-gate:open');

    // retryFailed forgets each row after re-pushing it.
    expect(DB::table('failed_jobs')->whereIn('uuid', [$uuid1, $uuid2])->count())->toBe(0);

    // The per-retry rows sit above the bulk umbrella row, so the search has to
    // scan back rather than read the latest.
    $auditRows = DB::table('dev_mode_audit')->orderByDesc('id')->limit(5)->get();
    $hasBulkRetry = false;
    foreach ($auditRows as $row) {
        $props = json_decode((string) $row->properties, true);
        if (($props['action'] ?? null) === AuditEvent::QueueBulkRetry->value) {
            $hasBulkRetry = true;
            break;
        }
    }
    expect($hasBulkRetry)->toBeTrue('Bulk-retry audit row should be written');
});
