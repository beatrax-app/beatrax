<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Listeners\RaiseReconsentAlertOnTokenFailure;
use Modules\EmailScan\Public\Events\InboxTokenFailed;

uses(RefreshDatabase::class);

/*
 * RaiseReconsentAlertOnTokenFailure writes a deduped system_alerts row
 * of kind 'oauth_reconsent_required' whenever an InboxTokenFailed
 * event fires. At most one un-acknowledged row exists per
 * (user_id, inbox_id) at a time — once acknowledged, a subsequent
 * failure re-raises a fresh row (the dedup window is "active alert",
 * not time-based).
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->userA = User::query()->create([
        'username' => 'reconsent-a',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // Two inboxes for user A — one Gmail, one Microsoft. The provider
    // string drives the alert message text branch in the listener.
    $now = CarbonImmutable::parse('2026-05-20 09:00:00')->toDateTimeString();
    $this->inboxAId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $this->userA->id,
        'provider' => 'gmail',
        'email' => 'reconsent-a@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->inboxBId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $this->userA->id,
        'provider' => 'microsoft',
        'email' => 'reconsent-b@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
});

it('writes a single system_alerts row when an InboxTokenFailed event fires for a Gmail inbox', function (): void {
    /** @var RaiseReconsentAlertOnTokenFailure $listener */
    $listener = $this->app->make(RaiseReconsentAlertOnTokenFailure::class);

    $listener->handle(new InboxTokenFailed(
        inboxId: $this->inboxAId,
        userId: $this->userA->id,
        provider: 'gmail',
    ));

    $rows = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'oauth_reconsent_required')
        ->get();

    expect($rows)->toHaveCount(1);

    /** @var SystemAlert $row */
    $row = $rows->first();
    expect($row->severity)->toBe('warning');
    expect($row->message)->toBe('Reconnect your Gmail');
    expect($row->metadata)->toBeArray();
    expect($row->metadata['inbox_id'] ?? null)->toBe($this->inboxAId);
    expect($row->metadata['provider'] ?? null)->toBe('gmail');
});

it('dedups a second InboxTokenFailed for the same (user, inbox) — exactly one active row remains', function (): void {
    /** @var RaiseReconsentAlertOnTokenFailure $listener */
    $listener = $this->app->make(RaiseReconsentAlertOnTokenFailure::class);

    $event = new InboxTokenFailed(
        inboxId: $this->inboxAId,
        userId: $this->userA->id,
        provider: 'gmail',
    );

    $listener->handle($event);
    $listener->handle($event);
    $listener->handle($event);

    $count = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'oauth_reconsent_required')
        ->whereNull('acknowledged_at')
        ->count();

    expect($count)->toBe(1);
});

it('writes a separate row for a different inbox owned by the same user', function (): void {
    /** @var RaiseReconsentAlertOnTokenFailure $listener */
    $listener = $this->app->make(RaiseReconsentAlertOnTokenFailure::class);

    $listener->handle(new InboxTokenFailed(
        inboxId: $this->inboxAId,
        userId: $this->userA->id,
        provider: 'gmail',
    ));
    $listener->handle(new InboxTokenFailed(
        inboxId: $this->inboxBId,
        userId: $this->userA->id,
        provider: 'microsoft',
    ));

    $rows = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'oauth_reconsent_required')
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2);

    /** @var SystemAlert $second */
    $second = $rows->last();
    expect($second->metadata['inbox_id'] ?? null)->toBe($this->inboxBId);
    expect($second->message)->toBe('Reconnect your Outlook');
});

it('re-raises a fresh alert when the previous one for the same inbox is acknowledged', function (): void {
    /** @var RaiseReconsentAlertOnTokenFailure $listener */
    $listener = $this->app->make(RaiseReconsentAlertOnTokenFailure::class);

    $event = new InboxTokenFailed(
        inboxId: $this->inboxAId,
        userId: $this->userA->id,
        provider: 'gmail',
    );

    $listener->handle($event);

    // Acknowledge the row out-of-band via a direct stamp — the dedup
    // window is "active alert", so the next handle() must create a
    // fresh row rather than no-op.
    SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'oauth_reconsent_required')
        ->update(['acknowledged_at' => CarbonImmutable::parse('2026-05-20 10:00:00')]);

    $listener->handle($event);

    $allRows = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'oauth_reconsent_required')
        ->get();
    expect($allRows)->toHaveCount(2);

    $activeRows = SystemAlert::query()
        ->where('user_id', $this->userA->id)
        ->where('kind', 'oauth_reconsent_required')
        ->whereNull('acknowledged_at')
        ->get();
    expect($activeRows)->toHaveCount(1);
});
