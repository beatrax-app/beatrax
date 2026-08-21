<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Enums\AuditEvent;
use Modules\DevMode\Internal\Http\Livewire\QueueInspectorPage;
use Modules\DevMode\Internal\Http\Livewire\TripleGateModal;

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

// executeBulkDelete re-checks all three gates itself, so a test that fires
// `triple-gate:confirmed` directly still has to seed them.
function queueGateOpenAllGates(): void
{
    queueGateSetDevModeFlag(true);
    /** @var Session $session */
    $session = app(Session::class);
    $session->put('dev_mode.advanced', true);
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

    expect(DB::table('failed_jobs')->whereIn('uuid', ['uuid-gate-untouched-1', 'uuid-gate-untouched-2'])->count())->toBe(2);
});

it('the triple-gate:confirmed event with the queue.bulk.delete discriminator triggers the actual delete + audit row', function (): void {
    $user = queueGateUser('q-gate-confirmed');
    $this->actingAs($user);
    queueGateOpenAllGates();

    seedFailedJobForGate('uuid-confirmed-1');
    seedFailedJobForGate('uuid-confirmed-2');

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', ['uuid-confirmed-1', 'uuid-confirmed-2'])
        // Shaped exactly as TripleGateModal::confirm() emits it — the typed
        // token has to be in the payload for the listener's own re-check.
        ->dispatch(
            'triple-gate:confirmed',
            command: 'queue.bulk.delete',
            args: ['tab' => 'failed', 'count' => 2],
            confirmed_typed: 'Beatrax',
        );

    expect(DB::table('failed_jobs')->whereIn('uuid', ['uuid-confirmed-1', 'uuid-confirmed-2'])->count())->toBe(0);

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
    queueGateOpenAllGates();

    seedFailedJobForGate('uuid-not-deleted-1');
    seedFailedJobForGate('uuid-not-deleted-2');

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', ['uuid-not-deleted-1', 'uuid-not-deleted-2'])
        ->dispatch(
            'triple-gate:confirmed',
            // An artisan-tier confirm arriving while the inspector happens to
            // be mounted with rows selected.
            command: 'db:restore',
            args: ['from' => '/tmp/backup.sqlite'],
            confirmed_typed: 'Beatrax',
        );

    expect(DB::table('failed_jobs')->whereIn('uuid', ['uuid-not-deleted-1', 'uuid-not-deleted-2'])->count())->toBe(2);
});

it('bulk delete on the pending tab deletes from the jobs table (kind switching by tab)', function (): void {
    $user = queueGateUser('q-gate-pending');
    $this->actingAs($user);
    queueGateOpenAllGates();

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
            confirmed_typed: 'Beatrax',
        );

    expect(DB::table('jobs')->whereIn('id', [$id1, $id2])->count())->toBe(0);
});

it('executeBulkDelete refuses when the dev_mode flag flipped off between gate confirm + listener fire', function (): void {
    $user = queueGateUser('q-gate-defense-dev-mode');
    $this->actingAs($user);

    seedFailedJobForGate('uuid-dim-1');
    seedFailedJobForGate('uuid-dim-2');

    // Advanced ON, typed token correct — but the env flag is OFF.
    /** @var Session $session */
    $session = app(Session::class);
    $session->put('dev_mode.advanced', true);
    queueGateSetDevModeFlag(false);

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', ['uuid-dim-1', 'uuid-dim-2'])
        ->dispatch(
            'triple-gate:confirmed',
            command: 'queue.bulk.delete',
            args: ['tab' => 'failed', 'count' => 2],
            confirmed_typed: 'Beatrax',
        );

    expect(DB::table('failed_jobs')->whereIn('uuid', ['uuid-dim-1', 'uuid-dim-2'])->count())->toBe(2);
});

it('executeBulkDelete refuses when the confirmed_typed token is wrong (defense-in-depth gate 3)', function (): void {
    $user = queueGateUser('q-gate-defense-typed');
    $this->actingAs($user);
    queueGateOpenAllGates();

    seedFailedJobForGate('uuid-typed-1');
    seedFailedJobForGate('uuid-typed-2');

    Livewire::test(QueueInspectorPage::class, ['tab' => 'failed'])
        ->set('selected', ['uuid-typed-1', 'uuid-typed-2'])
        ->dispatch(
            'triple-gate:confirmed',
            command: 'queue.bulk.delete',
            args: ['tab' => 'failed', 'count' => 2],
            confirmed_typed: 'BEATRAX', // wrong case — gate is case-sensitive
        );

    expect(DB::table('failed_jobs')->whereIn('uuid', ['uuid-typed-1', 'uuid-typed-2'])->count())->toBe(2);
});

it('the triple-gate is enforced through the global TripleGateModal — its three gates apply to queue bulk delete (composition check)', function (): void {
    // Only that the modal is in the path — TripleGateTest covers the
    // gate-by-gate rejections.
    $user = queueGateUser('q-gate-composition');
    queueGateSetDevModeFlag(true);
    $this->actingAs($user);
    // Do NOT set session('dev_mode.advanced') = true; Gate 2 fails.

    Livewire::test(TripleGateModal::class)
        ->dispatch('triple-gate:open', command: 'queue.bulk.delete', args: ['tab' => 'failed'])
        ->set('typed', 'Beatrax')
        ->call('confirm')
        ->assertHasErrors(['_gate'])
        ->assertNotDispatched('triple-gate:confirmed');
});
