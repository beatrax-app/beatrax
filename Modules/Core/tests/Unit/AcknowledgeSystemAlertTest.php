<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->userA = User::query()->create([
        'username' => 'ack-a',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->userB = User::query()->create([
        'username' => 'ack-b',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->frozenNow = CarbonImmutable::parse('2026-05-20 09:00:00');
    $clock = $this->createStub(Clock::class);
    $clock->method('now')->willReturn($this->frozenNow);
    $this->app->instance(Clock::class, $clock);
});

it('stamps acknowledged_at = clock.now() on a successful acknowledge', function (): void {
    $id = $this->db->connection()->table('system_alerts')->insertGetId([
        'user_id' => $this->userA->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'fixture',
        'metadata' => null,
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);

    /** @var AcknowledgeSystemAlert $action */
    $action = $this->app->make(AcknowledgeSystemAlert::class);
    $result = $action($id, $this->userA);

    expect($result)->toBeInstanceOf(SystemAlert::class);
    expect($result->id)->toBe($id);
    expect($result->acknowledged_at?->equalTo($this->frozenNow))->toBeTrue();

    $row = $this->db->connection()->table('system_alerts')->where('id', $id)->first();
    expect($row?->acknowledged_at)->toBe('2026-05-20 09:00:00');
});

// A system-wide row is one row the whole household sees, so it is dismissed
// per reader and never stamped -- see
// AWholeHouseholdAlertNeedsAWholeHouseholdToDismissItTest.
it('lets a user dismiss a system-wide (user_id NULL) alert without stamping the shared row', function (): void {
    $id = $this->db->connection()->table('system_alerts')->insertGetId([
        'user_id' => null,
        'kind' => 'wal_mode_missing',
        'severity' => 'warning',
        'message' => 'system-wide',
        'metadata' => null,
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);

    /** @var AcknowledgeSystemAlert $action */
    $action = $this->app->make(AcknowledgeSystemAlert::class);
    $result = $action($id, $this->userA);

    expect($result->id)->toBe($id);
    $row = $this->db->connection()->table('system_alerts')->where('id', $id)->first();
    expect($row?->acknowledged_at)->toBeNull();

    $ack = $this->db->connection()->table('system_alert_acknowledgements')
        ->where('system_alert_id', $id)
        ->where('user_id', $this->userA->id)
        ->first();
    expect($ack?->acknowledged_at)->toBe('2026-05-20 09:00:00');
});

it('refuses cross-user acknowledge with NotFoundHttpException and leaves the row untouched', function (): void {
    $id = $this->db->connection()->table('system_alerts')->insertGetId([
        'user_id' => $this->userB->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'B private',
        'metadata' => null,
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);

    /** @var AcknowledgeSystemAlert $action */
    $action = $this->app->make(AcknowledgeSystemAlert::class);

    expect(fn () => $action($id, $this->userA))
        ->toThrow(NotFoundHttpException::class, 'System alert not found.');

    $row = $this->db->connection()->table('system_alerts')->where('id', $id)->first();
    expect($row?->acknowledged_at)->toBeNull();
});

it('is idempotent on already-acknowledged rows: second call returns the row without re-writing acknowledged_at', function (): void {
    $originalAck = '2026-05-19 09:00:00';
    $id = $this->db->connection()->table('system_alerts')->insertGetId([
        'user_id' => $this->userA->id,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'fixture',
        'metadata' => null,
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => $originalAck,
    ]);

    /** @var AcknowledgeSystemAlert $action */
    $action = $this->app->make(AcknowledgeSystemAlert::class);
    $result = $action($id, $this->userA);

    expect($result->id)->toBe($id);

    // acknowledged_at MUST still be the original stamp — not the frozen clock value.
    $row = $this->db->connection()->table('system_alerts')->where('id', $id)->first();
    expect($row?->acknowledged_at)->toBe($originalAck);
});

it('404s when the alertId does not exist at all', function (): void {
    /** @var AcknowledgeSystemAlert $action */
    $action = $this->app->make(AcknowledgeSystemAlert::class);
    expect(fn () => $action(99999, $this->userA))
        ->toThrow(NotFoundHttpException::class, 'System alert not found.');
});

it('resolves SystemAlertQuery + AcknowledgeSystemAlert from the container', function (): void {
    // Resolvable, not identical: a stateless service that dispatches events is
    // deliberately no longer a singleton, because Event::fake() cannot reach a
    // dispatcher one has already captured. ASingletonNeverCapturesTheDispatcher
    // holds that line; this holds the wiring.
    expect($this->app->make(SystemAlertQuery::class))->toBeInstanceOf(SystemAlertQuery::class)
        ->and($this->app->make(AcknowledgeSystemAlert::class))->toBeInstanceOf(AcknowledgeSystemAlert::class);
});
