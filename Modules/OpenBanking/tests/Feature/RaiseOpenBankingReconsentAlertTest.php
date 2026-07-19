<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Listeners\RaiseOpenBankingReconsentAlert;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;

uses(RefreshDatabase::class);

/*
 * RaiseOpenBankingReconsentAlert (19-06 Task 2, D-09): writes a deduped
 * system_alerts row of kind 'open_banking_reconsent_required' whenever
 * an OpenBankingConsentFailed event fires. At most one un-acknowledged
 * row exists per (user_id, connection_id) at a time — once
 * acknowledged, a subsequent failure re-raises a fresh row (the dedup
 * window is "active alert", not time-based). The listener never throws
 * upward even if alert persistence itself fails (T-19-06-03).
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->userA = User::query()->create([
        'username' => 'ob-reconsent-a',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    $now = CarbonImmutable::parse('2026-07-19 09:00:00')->toDateTimeString();
    $this->connectionAId = (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $this->userA->id,
        'institution_id' => 'ASNBNL21',
        'bank_display_name' => 'ASN Bank',
        'enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->connectionBId = (int) $db->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $this->userA->id,
        'institution_id' => 'SNSBNL21',
        'bank_display_name' => 'SNS (de Volksbank)',
        'enabled' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
});

it('writes a single system_alerts row when an OpenBankingConsentFailed event fires', function (): void {
    /** @var RaiseOpenBankingReconsentAlert $listener */
    $listener = $this->app->make(RaiseOpenBankingReconsentAlert::class);

    $listener->handle(new OpenBankingConsentFailed(
        connectionId: $this->connectionAId,
        userId: $this->userA->id,
        reason: 'consent_expired',
    ));

    $rows = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'open_banking_reconsent_required')
        ->get();

    expect($rows)->toHaveCount(1);

    /** @var SystemAlert $row */
    $row = $rows->first();
    expect($row->severity)->toBe('warning');
    expect($row->message)->toBe('Reconnect your bank');
    expect($row->metadata)->toBeArray();
    expect($row->metadata['connection_id'] ?? null)->toBe($this->connectionAId);
    expect($row->metadata['reason'] ?? null)->toBe('consent_expired');
});

it('dedups a second OpenBankingConsentFailed for the same (user, connection) — exactly one active row remains', function (): void {
    /** @var RaiseOpenBankingReconsentAlert $listener */
    $listener = $this->app->make(RaiseOpenBankingReconsentAlert::class);

    $event = new OpenBankingConsentFailed(
        connectionId: $this->connectionAId,
        userId: $this->userA->id,
        reason: 'consent_expired',
    );

    $listener->handle($event);
    $listener->handle($event);
    $listener->handle($event);

    $count = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'open_banking_reconsent_required')
        ->whereNull('acknowledged_at')
        ->count();

    expect($count)->toBe(1);
});

it('writes a separate row for a different connection owned by the same user', function (): void {
    /** @var RaiseOpenBankingReconsentAlert $listener */
    $listener = $this->app->make(RaiseOpenBankingReconsentAlert::class);

    $listener->handle(new OpenBankingConsentFailed(
        connectionId: $this->connectionAId,
        userId: $this->userA->id,
        reason: 'consent_expired',
    ));
    $listener->handle(new OpenBankingConsentFailed(
        connectionId: $this->connectionBId,
        userId: $this->userA->id,
        reason: 'sca_failed',
    ));

    $rows = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'open_banking_reconsent_required')
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2);

    /** @var SystemAlert $second */
    $second = $rows->last();
    expect($second->metadata['connection_id'] ?? null)->toBe($this->connectionBId);
});

it('does not confuse connection_id=1 with connection_id=10/11 under the LIKE dedup fallback', function (): void {
    /** @var RaiseOpenBankingReconsentAlert $listener */
    $listener = $this->app->make(RaiseOpenBankingReconsentAlert::class);

    // Simulate the two-digit connection ids directly via raw inserts so
    // the dedup query is exercised against realistic multi-digit
    // metadata payloads regardless of which code path (json_extract or
    // LIKE fallback) the local SQLite build takes.
    $now = CarbonImmutable::parse('2026-07-19 09:00:00');
    SystemAlert::query()->create([
        'user_id' => $this->userA->id,
        'kind' => 'open_banking_reconsent_required',
        'severity' => 'warning',
        'message' => 'Reconnect your bank',
        'metadata' => ['connection_id' => 10, 'reason' => 'consent_expired'],
    ]);

    $listener->handle(new OpenBankingConsentFailed(
        connectionId: 1,
        userId: $this->userA->id,
        reason: 'consent_expired',
    ));

    $rows = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'open_banking_reconsent_required')
        ->whereNull('acknowledged_at')
        ->get();

    expect($rows)->toHaveCount(2);
    $connectionIds = $rows->map(fn (SystemAlert $row): mixed => $row->metadata['connection_id'] ?? null)->all();
    expect($connectionIds)->toEqualCanonicalizing([10, 1]);
});

it('re-raises a fresh alert when the previous one for the same connection is acknowledged', function (): void {
    /** @var RaiseOpenBankingReconsentAlert $listener */
    $listener = $this->app->make(RaiseOpenBankingReconsentAlert::class);

    $event = new OpenBankingConsentFailed(
        connectionId: $this->connectionAId,
        userId: $this->userA->id,
        reason: 'consent_expired',
    );

    $listener->handle($event);

    // Acknowledge the row out-of-band via a direct stamp — the dedup
    // window is "active alert", so the next handle() must create a
    // fresh row rather than no-op.
    SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'open_banking_reconsent_required')
        ->update(['acknowledged_at' => CarbonImmutable::parse('2026-07-19 10:00:00')]);

    $listener->handle($event);

    $allRows = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'open_banking_reconsent_required')
        ->get();
    expect($allRows)->toHaveCount(2);

    $activeRows = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'open_banking_reconsent_required')
        ->whereNull('acknowledged_at')
        ->get();
    expect($activeRows)->toHaveCount(1);
});

it('the listener is registered against OpenBankingConsentFailed and reachable via the event dispatcher', function (): void {
    Event::dispatch(new OpenBankingConsentFailed(
        connectionId: $this->connectionAId,
        userId: $this->userA->id,
        reason: 'consent_expired',
    ));

    $count = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'open_banking_reconsent_required')
        ->count();

    expect($count)->toBe(1);
});

it('never throws upward even when the system_alerts insert itself fails', function (): void {
    /** @var RaiseOpenBankingReconsentAlert $listener */
    $listener = $this->app->make(RaiseOpenBankingReconsentAlert::class);

    // Force a real persistence failure inside the try/catch (not the
    // pre-check query): system_alerts.user_id is a foreign key against
    // users (foreign_key_constraints=true on the testing connection),
    // so a nonexistent user id trips a genuine constraint-violation
    // QueryException at INSERT time — the defence-in-depth path this
    // listener exists to swallow.
    $nonexistentUserId = 999_999;

    $listener->handle(new OpenBankingConsentFailed(
        connectionId: $this->connectionAId,
        userId: $nonexistentUserId,
        reason: 'consent_expired',
    ));
})->throwsNoExceptions();
