<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\InvalidStateTransitionException;
use Modules\EmailScan\Public\Dto\ScanCursor;

uses(RefreshDatabase::class);

// The machine is the sole legal mutator of inbox_scan_state's status,
// retry_attempts and cursor columns, so a forbidden transition has to raise
// rather than silently corrupt the lifecycle. The helpers are closures on
// $this because a top-level function could not reach the protected $app.

beforeEach(function (): void {
    $this->seedInbox = function (string $provider = 'gmail', string $status = 'idle', int $retryAttempts = 0): int {
        $username = $provider.'-'.uniqid();
        $user = User::query()->create([
            'username' => $username,
            'password' => 'fixture',
            'period_start_day' => 1,
        ]);

        /** @var DatabaseManager $db */
        $db = $this->app->make(DatabaseManager::class);
        $now = CarbonImmutable::now()->toDateTimeString();

        $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
            'user_id' => $user->id,
            'provider' => $provider,
            'email' => $username.'@example.com',
            'backfill_window_months' => 3,
            'backfill_progress' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $db->connection()->table('inbox_scan_state')->insert([
            'user_id' => $user->id,
            'inbox_id' => $inboxId,
            'folder' => 'INBOX',
            'status' => $status,
            'retry_attempts' => $retryAttempts,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inboxId;
    };

    $this->readState = function (int $inboxId): stdClass {
        /** @var DatabaseManager $db */
        $db = $this->app->make(DatabaseManager::class);
        $row = $db->connection()
            ->table('inbox_scan_state')
            ->where('inbox_id', $inboxId)
            ->where('folder', 'INBOX')
            ->first();

        expect($row)->not->toBeNull('Expected inbox_scan_state row for inbox '.$inboxId);

        /** @var stdClass $row */
        return $row;
    };

    $this->makeMachine = function (): InboxScanStateMachine {
        return new InboxScanStateMachine(
            $this->app->make(DatabaseManager::class),
            $this->app->make(Clock::class),
        );
    };
});

it('applyStatus(idle → backfilling) advances last_scan_at', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $before = ($this->readState)($inboxId);
    expect($before->last_scan_at)->toBeNull();

    ($this->makeMachine)()->applyStatus($inboxId, 'backfilling');

    $after = ($this->readState)($inboxId);
    expect($after->status)->toBe('backfilling');
    expect($after->last_scan_at)->not->toBeNull();
});

it('applyStatus(backfilling → idle) is allowed (success path)', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'backfilling');
    $sm->applyStatus($inboxId, 'idle');

    expect(($this->readState)($inboxId)->status)->toBe('idle');
});

it('applyStatus rejects idle → rate_limited as invalid', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();

    expect(fn (): mixed => $sm->applyStatus($inboxId, 'rate_limited'))
        ->toThrow(InvalidStateTransitionException::class);

    expect(($this->readState)($inboxId)->status)->toBe('idle');
});

it('applyRateLimited(scanning → rate_limited) flips status, bumps retry_attempts, sets error_message', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'scanning');

    $sm->applyRateLimited($inboxId, 60);

    $row = ($this->readState)($inboxId);
    expect($row->status)->toBe('rate_limited');
    expect((int) $row->retry_attempts)->toBe(1);
    expect((string) $row->error_message)->toContain('Retry after 60s');
});

it('applyRateLimited called twice bumps retry_attempts to 2', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'scanning');
    $sm->applyRateLimited($inboxId, 60);
    // The pipeline goes rate_limited → scanning between attempts.
    $sm->applyStatus($inboxId, 'scanning');
    $sm->applyRateLimited($inboxId, 120);

    $row = ($this->readState)($inboxId);
    expect((int) $row->retry_attempts)->toBe(2);
    expect((string) $row->error_message)->toContain('Retry after 120s');
});

it('resetRetryAttempts zeros the counter', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'scanning');
    $sm->applyRateLimited($inboxId, 60);

    expect((int) ($this->readState)($inboxId)->retry_attempts)->toBe(1);

    $sm->resetRetryAttempts($inboxId);

    expect((int) ($this->readState)($inboxId)->retry_attempts)->toBe(0);
});

it('applyStatus(rate_limited → needs_reauth) is allowed', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'scanning');
    $sm->applyRateLimited($inboxId, 60);

    $sm->applyStatus($inboxId, 'needs_reauth', 'OAuth grant revoked.');

    expect(($this->readState)($inboxId)->status)->toBe('needs_reauth');
});

it('applyStatus rejects needs_reauth → scanning (only needs_reauth → idle is allowed)', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'needs_reauth', 'OAuth grant revoked.');

    expect(fn (): mixed => $sm->applyStatus($inboxId, 'scanning'))
        ->toThrow(InvalidStateTransitionException::class);

    expect(($this->readState)($inboxId)->status)->toBe('needs_reauth');
});

it('applyStatus(needs_reauth → needs_reauth) is re-entrant safe', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'needs_reauth', 'first.');

    $sm->applyStatus($inboxId, 'needs_reauth', 'second.');

    $row = ($this->readState)($inboxId);
    expect($row->status)->toBe('needs_reauth');
    expect((string) $row->error_message)->toBe('second.');
});

it('applyStatus(idle → idle) is permitted (no-senders early-exit path)', function (): void {
    // BackfillInboxJob's "no senders configured" early exit sets idle on a row
    // that is already idle.
    $inboxId = ($this->seedInbox)(status: 'idle');

    ($this->makeMachine)()->applyStatus($inboxId, 'idle', 'No known senders are configured for this user.');

    $row = ($this->readState)($inboxId);
    expect($row->status)->toBe('idle');
    expect((string) $row->error_message)->toBe('No known senders are configured for this user.');
});

it('applyStatus(backfilling → backfilling) is re-entrant safe (recovery dispatch resume)', function (): void {
    // A worker that died without flipping the row back to idle leaves a
    // recovery dispatch to re-enter backfilling, and a throw would stall it.
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'backfilling');

    $sm->applyStatus($inboxId, 'backfilling');

    expect(($this->readState)($inboxId)->status)->toBe('backfilling');
});

it('applyStatus(scanning → scanning) is re-entrant safe (recovery dispatch resume)', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'scanning');

    $sm->applyStatus($inboxId, 'scanning');

    expect(($this->readState)($inboxId)->status)->toBe('scanning');
});

it('recordBackfillProgress writes the encoded payload to the inboxes column', function (): void {
    $inboxId = ($this->seedInbox)(status: 'backfilling');
    $sm = ($this->makeMachine)();

    $sm->recordBackfillProgress($inboxId, [
        'fetched_count' => 12,
        'total_estimated' => 30,
        'last_message_date' => '2026-05-15 12:00:00',
    ]);

    $row = app(DatabaseManager::class)
        ->connection()
        ->table('inboxes')
        ->where('id', $inboxId)
        ->first(['backfill_progress']);

    expect($row)->not->toBeNull();
    $payload = json_decode((string) $row->backfill_progress, true);
    expect($payload)->toBe([
        'fetched_count' => 12,
        'total_estimated' => 30,
        'last_message_date' => '2026-05-15 12:00:00',
    ]);
});

it('recordBackfillProgress(null) clears the inboxes column so the strip hides', function (): void {
    $inboxId = ($this->seedInbox)(status: 'backfilling');
    $sm = ($this->makeMachine)();
    $sm->recordBackfillProgress($inboxId, [
        'fetched_count' => 1,
        'total_estimated' => 2,
        'last_message_date' => null,
    ]);

    $sm->recordBackfillProgress($inboxId, null);

    $row = app(DatabaseManager::class)
        ->connection()
        ->table('inboxes')
        ->where('id', $inboxId)
        ->first(['backfill_progress']);

    expect($row)->not->toBeNull();
    expect($row->backfill_progress)->toBeNull();
});

it('applyStatus does NOT advance last_scan_at for rate_limited / needs_reauth / error transitions', function (): void {
    $inboxId = ($this->seedInbox)(status: 'idle');
    $sm = ($this->makeMachine)();
    $sm->applyStatus($inboxId, 'scanning');

    $sawAtScanning = ($this->readState)($inboxId)->last_scan_at;
    expect($sawAtScanning)->not->toBeNull();

    $sm->applyStatus($inboxId, 'error', 'transient failure.');

    // The last_scan_at column must NOT advance — the timestamp staying
    // frozen IS the signal that the inbox is stuck.
    expect(($this->readState)($inboxId)->last_scan_at)->toBe($sawAtScanning);
});

it('backoffForAttempt clamps to the schedule bounds', function (): void {
    $sm = ($this->makeMachine)();

    expect($sm->backoffForAttempt(0))->toBe(60);
    expect($sm->backoffForAttempt(1))->toBe(300);
    expect($sm->backoffForAttempt(2))->toBe(900);
    expect($sm->backoffForAttempt(3))->toBe(3600);
    expect($sm->backoffForAttempt(99))->toBe(3600);
    expect($sm->backoffForAttempt(-5))->toBe(60);
});

it('recordCursor(gmail) writes last_history_id when the inbox is a gmail inbox', function (): void {
    $inboxId = ($this->seedInbox)(provider: 'gmail');

    ($this->makeMachine)()->recordCursor($inboxId, ScanCursor::gmail('h-12400'));

    $row = ($this->readState)($inboxId);
    expect($row->last_history_id)->toBe('h-12400');
    expect($row->last_delta_link)->toBeNull();
});

it('recordCursor(microsoft) writes last_delta_link when the inbox is a microsoft inbox', function (): void {
    $inboxId = ($this->seedInbox)(provider: 'microsoft');
    $deltaLink = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/$delta?$deltatoken=xyz';

    ($this->makeMachine)()->recordCursor($inboxId, ScanCursor::microsoft($deltaLink));

    $row = ($this->readState)($inboxId);
    expect($row->last_delta_link)->toBe($deltaLink);
    expect($row->last_history_id)->toBeNull();
});

it('recordCursor with an empty cursor is a no-op (no updated_at advance, no column write)', function (): void {
    $inboxId = ($this->seedInbox)(provider: 'gmail');
    $before = ($this->readState)($inboxId);

    ($this->makeMachine)()->recordCursor($inboxId, ScanCursor::emptyFor('gmail'));

    $after = ($this->readState)($inboxId);
    expect($after->updated_at)->toBe($before->updated_at);
    expect($after->last_history_id)->toBeNull();
});

it('recordCursor rejects a provider mismatch (microsoft cursor on a gmail inbox)', function (): void {
    $inboxId = ($this->seedInbox)(provider: 'gmail');

    $deltaLink = 'https://graph.microsoft.com/v1.0/me/messages/$delta?$deltatoken=xyz';
    $sm = ($this->makeMachine)();

    expect(fn (): mixed => $sm->recordCursor($inboxId, ScanCursor::microsoft($deltaLink)))
        ->toThrow(InvalidArgumentException::class);

    $row = ($this->readState)($inboxId);
    expect($row->last_history_id)->toBeNull();
    expect($row->last_delta_link)->toBeNull();
});

it('recordCursor against a missing inbox raises RuntimeException', function (): void {
    $sm = ($this->makeMachine)();
    expect(fn (): mixed => $sm->recordCursor(99999, ScanCursor::gmail('h-1')))
        ->toThrow(RuntimeException::class);
});

it('applyStatus against a missing inbox raises RuntimeException', function (): void {
    $sm = ($this->makeMachine)();
    expect(fn (): mixed => $sm->applyStatus(99999, 'backfilling'))
        ->toThrow(RuntimeException::class);
});

it('applyRateLimited against a missing inbox raises RuntimeException', function (): void {
    $sm = ($this->makeMachine)();
    expect(fn (): mixed => $sm->applyRateLimited(99999, 60))
        ->toThrow(RuntimeException::class);
});

it('ALLOWED_TRANSITIONS map permits every transition BackfillInboxJob currently exercises', function (): void {
    // Each pair is a transition BackfillInboxJob really performs: the
    // no-senders early exit, the unknown-provider arm, branch entry, the
    // rate-limit / invalid-grant / throwable catches, and the success path.
    $cases = [
        ['idle', 'idle'],
        ['idle', 'error'],
        ['idle', 'backfilling'],
        ['backfilling', 'rate_limited'],
        ['backfilling', 'needs_reauth'],
        ['backfilling', 'error'],
        ['backfilling', 'idle'],
    ];

    foreach ($cases as [$from, $to]) {
        $inboxId = ($this->seedInbox)(status: $from);
        try {
            ($this->makeMachine)()->applyStatus($inboxId, $to);
        } catch (InvalidStateTransitionException $e) {
            test()->fail("Transition {$from} → {$to} should be permitted but raised: ".$e->getMessage());
        }
        expect(($this->readState)($inboxId)->status)->toBe($to);
    }
});
