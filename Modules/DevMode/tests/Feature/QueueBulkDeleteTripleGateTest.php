<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Enums\AuditEvent;
use Modules\DevMode\Internal\Http\Livewire\QueueInspectorPage;
use Modules\DevMode\Internal\Http\Livewire\TripleGateModal;

/*
 * Phase 16-06 Task 1 — Triple-gate enforcement on bulk delete (D-22 / D-34).
 *
 * Bulk delete is the destructive sibling of bulk retry. Per CONTEXT
 * D-22 ("Triple-gate also applies to bulk DESTRUCTIVE queue actions"),
 * the bulk-delete affordance MUST route through the existing
 * TripleGateModal (the same one 16-04b lands for destructive artisan
 * commands).
 *
 * The mitigation surface is two-layered (defense-in-depth, mirroring
 * the artisan-runner pattern):
 *   1. QueueInspectorPage::bulkDeleteRequest() dispatches
 *      `triple-gate:open` — the user-visible affordance.
 *   2. QueueInspectorPage::executeBulkDelete() is the listener for
 *      `triple-gate:confirmed`; it discriminates by the command
 *      string so unrelated gate confirms (artisan-tier destructive)
 *      don't accidentally delete queue rows.
 *
 * The gate itself enforces the three locks server-side
 * (DevModeFlag + session.advanced + typed-name); those tests live in
 * TripleGateTest. This file exercises the bulk-delete-specific
 * round-trip end-to-end.
 */

function queueGateUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function queueGateSetDevModeFlag(bool $on): void
{
    /** @var Repository $config */
    $config = app(Repository::class);
    $config->set('app.dev_mode', $on);
}

function seedFailedJobForGate(string $uuid): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"data":{"command":"O:8:\"stdClass\":0:{}"}}',
        'exception' => 'RuntimeException: boom',
        'failed_at' => CarbonImmutable::now(),
    ]);
}

it('clicking "Delete N jobs" dispatches triple-gate:open with the queue.bulk.delete discriminator', function (): void {
    $user = queueGateUser('q-gate-dispatch');
    $this->actingAs($user);

    seedFailedJobForGate('uuid-gate-1');
    seedFailedJobForGate('uuid-gate-2');

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', ['uuid-gate-1', 'uuid-gate-2'])
        ->call('bulkDeleteRequest')
        ->assertDispatched('triple-gate:open', function (string $event, array $params): bool {
            return ($params['command'] ?? null) === 'queue.bulk.delete'
                && ($params['args']['tab'] ?? null) === 'failed'
                && ($params['args']['count'] ?? null) === 2;
        });
});

it('the rows are NOT deleted until the triple-gate confirmed event fires', function (): void {
    $user = queueGateUser('q-gate-not-deleted-until-confirmed');
    $this->actingAs($user);

    seedFailedJobForGate('uuid-gate-untouched-1');
    seedFailedJobForGate('uuid-gate-untouched-2');

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', ['uuid-gate-untouched-1', 'uuid-gate-untouched-2'])
        ->call('bulkDeleteRequest');

    // The dispatch happened but no listener has fired yet → rows present.
    expect(DB::table('failed_jobs')->whereIn('uuid', ['uuid-gate-untouched-1', 'uuid-gate-untouched-2'])->count())->toBe(2);
});

it('the triple-gate:confirmed event with the queue.bulk.delete discriminator triggers the actual delete + audit row', function (): void {
    $user = queueGateUser('q-gate-confirmed');
    $this->actingAs($user);

    seedFailedJobForGate('uuid-confirmed-1');
    seedFailedJobForGate('uuid-confirmed-2');

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', ['uuid-confirmed-1', 'uuid-confirmed-2'])
        // Simulate the gate's confirmed dispatch back to the page —
        // mirrors what TripleGateModal::confirm() emits after the
        // three gates pass.
        ->dispatch(
            'triple-gate:confirmed',
            command: 'queue.bulk.delete',
            args: ['tab' => 'failed', 'count' => 2],
        );

    // Both rows should now be gone.
    expect(DB::table('failed_jobs')->whereIn('uuid', ['uuid-confirmed-1', 'uuid-confirmed-2'])->count())->toBe(0);

    // An audit row with action=queue.bulk.delete should have landed.
    $auditRow = DB::table('dev_mode_audit')->latest('id')->first();
    expect($auditRow)->not->toBeNull();
    $properties = json_decode((string) $auditRow->properties, true);
    expect($properties['action'] ?? null)->toBe(AuditEvent::QueueBulkDelete->value);
    expect($properties['context']['kind'] ?? null)->toBe('failed');
    expect($properties['context']['count'] ?? null)->toBe(2);
});

it('a triple-gate:confirmed event with a DIFFERENT command (NOT queue.bulk.delete) does NOT delete queue rows', function (): void {
    $user = queueGateUser('q-gate-discriminator');
    $this->actingAs($user);

    seedFailedJobForGate('uuid-not-deleted-1');
    seedFailedJobForGate('uuid-not-deleted-2');

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', ['uuid-not-deleted-1', 'uuid-not-deleted-2'])
        ->dispatch(
            'triple-gate:confirmed',
            // Simulate an unrelated destructive command's confirm event
            // (artisan-tier destructive) arriving while the inspector
            // is mounted with queue rows selected.
            command: 'db:restore',
            args: ['from' => '/tmp/backup.sqlite'],
        );

    // Queue rows untouched — the discriminator did its job.
    expect(DB::table('failed_jobs')->whereIn('uuid', ['uuid-not-deleted-1', 'uuid-not-deleted-2'])->count())->toBe(2);
});

it('bulk delete on the pending tab deletes from the jobs table (kind switching by tab)', function (): void {
    $user = queueGateUser('q-gate-pending');
    $this->actingAs($user);

    $id1 = (int) DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => CarbonImmutable::now()->getTimestamp(),
        'created_at' => CarbonImmutable::now()->getTimestamp(),
    ]);
    $id2 = (int) DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => CarbonImmutable::now()->getTimestamp(),
        'created_at' => CarbonImmutable::now()->getTimestamp(),
    ]);

    Livewire::test(QueueInspectorPage::class, ['tab' => 'pending'])
        ->set('selected', [(string) $id1, (string) $id2])
        ->dispatch(
            'triple-gate:confirmed',
            command: 'queue.bulk.delete',
            args: ['tab' => 'pending', 'count' => 2],
        );

    expect(DB::table('jobs')->whereIn('id', [$id1, $id2])->count())->toBe(0);
});

it('the triple-gate is enforced through the global TripleGateModal — its three gates apply to queue bulk delete (D-22 composition check)', function (): void {
    // This is a composition check — the actual gate-by-gate
    // rejection paths are exhaustively covered by TripleGateTest.
    // Here we just verify the modal still rejects when the three
    // locks aren't satisfied (Gate 2: session.advanced not set).
    $user = queueGateUser('q-gate-composition');
    queueGateSetDevModeFlag(true);
    $this->actingAs($user);
    // Do NOT set session('dev_mode.advanced') = true; Gate 2 fails.

    Livewire::test(TripleGateModal::class)
        ->dispatch('triple-gate:open', command: 'queue.bulk.delete', args: ['tab' => 'failed'])
        ->set('typed', 'beatrax')
        ->call('confirm')
        ->assertHasErrors(['_gate'])
        ->assertNotDispatched('triple-gate:confirmed');
});
