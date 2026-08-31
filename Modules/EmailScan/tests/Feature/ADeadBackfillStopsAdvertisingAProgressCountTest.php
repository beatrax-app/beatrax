<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Public\Enums\InboxScanStatus;

uses(RefreshDatabase::class);

// InboxHealthDto states the contract the inboxes page relies on: the backfill
// counters are null when no backfill is running, so the strip branches on
// absence. Only the success path honoured it — a backfill that died into
// needs_reauth or error kept its counters, and the strip kept polling them
// every two seconds above the same row's "Needs reauth" badge.
//
// status lives in inbox_scan_state and the counters live in inboxes, which is
// how the two drifted apart; the machine writes both under one transaction.

/** @param  array{fetched_count: int, total_estimated: int}|null  $progress */
function deadBackfillInbox(string $status, ?array $progress): int
{
    $username = 'backfill-'.uniqid();
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => $username.'@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => $progress === null ? null : json_encode($progress, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => $status,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

function readProgress(int $inboxId): ?string
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $value = $db->connection()->table('inboxes')->where('id', $inboxId)->value('backfill_progress');

    return is_string($value) ? $value : null;
}

it('drops the counters when a backfill dies into a state that cannot scan', function (string $status): void {
    $inboxId = deadBackfillInbox('backfilling', ['fetched_count' => 42, 'total_estimated' => 100]);

    app(InboxScanStateMachine::class)->applyStatus($inboxId, $status, 'stopped');

    expect(readProgress($inboxId))->toBeNull(
        'an inbox in '.$status.' must not still advertise a backfill count',
    );
})->with([
    InboxScanStatus::NeedsReauth->value,
    InboxScanStatus::Error->value,
    InboxScanStatus::Idle->value,
]);

it('keeps the counters while the backfill is still in flight', function (string $status): void {
    $inboxId = deadBackfillInbox('backfilling', ['fetched_count' => 42, 'total_estimated' => 100]);

    app(InboxScanStateMachine::class)->applyStatus($inboxId, $status, null);

    // A paused backfill resumes, so it must keep its place in the mailbox.
    expect(readProgress($inboxId))->toContain('"fetched_count":42');
})->with([
    InboxScanStatus::Backfilling->value,
    InboxScanStatus::RateLimited->value,
]);

it('leaves an inbox that never had counters untouched', function (): void {
    $inboxId = deadBackfillInbox('scanning', null);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $before = $db->connection()->table('inboxes')->where('id', $inboxId)->value('updated_at');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
    app(InboxScanStateMachine::class)->applyStatus($inboxId, InboxScanStatus::Idle->value);
    CarbonImmutable::setTestNow();

    // The clear is guarded on whereNotNull, so an ordinary idle/scanning cycle
    // does not rewrite the row just to prove nothing changed.
    expect($db->connection()->table('inboxes')->where('id', $inboxId)->value('updated_at'))->toBe($before);
});

// The seam above only holds from the transition onward. A row stranded before
// it stays stranded: scanNow refuses a revoked inbox by design, so nothing a
// reader can press repairs one. The migration is the only way back for them.
it('repairs an inbox already stranded before the seam existed', function (): void {
    $stranded = deadBackfillInbox('needs_reauth', ['fetched_count' => 42, 'total_estimated' => 100]);
    $running = deadBackfillInbox('backfilling', ['fetched_count' => 7, 'total_estimated' => 50]);
    $paused = deadBackfillInbox('rate_limited', ['fetched_count' => 9, 'total_estimated' => 50]);

    $migration = require base_path('Modules/EmailScan/Database/Migrations/2026_08_31_000001_stop_a_dead_backfill_from_reporting_progress.php');
    $migration->up();

    // toContain takes needles, not a message: a second string is a second
    // needle, and the assertion then looks for the explanation in the JSON.
    expect(readProgress($stranded))->toBeNull('a revoked inbox must stop reporting a backfill')
        ->and(readProgress($running))->toContain('"fetched_count":7')
        ->and(readProgress($paused))->toContain('"fetched_count":9');
});
