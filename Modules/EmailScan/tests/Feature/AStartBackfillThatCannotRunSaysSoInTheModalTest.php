<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Internal\Http\Livewire\BackfillWindowModal;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

// The [Edit] link on an inbox row carries no disabled condition, so "Start
// backfill" is pressable while the mailbox is in a state BackfillInboxJob
// refuses on its opening transition. What the reader got was the modal
// closing: no toast, no log, no state change, and no backfill.

function refusedBackfillUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function refusedBackfillInbox(User $owner, string $status): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
        'email' => $owner->username.'@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => $status,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

function refusedBackfillWindowMonths(int $inboxId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('inboxes')->where('id', $inboxId)->value('backfill_window_months');
}

function refusedBackfillRecorder(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = [
                'level' => is_string($level) ? $level : (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };
}

it('answers in the modal and dispatches nothing when the inbox needs a reconnect', function (): void {
    Bus::fake();
    $user = refusedBackfillUser('backfill-reauth');
    $inboxId = refusedBackfillInbox($user, InboxScanStatus::NeedsReauth->value);
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', $inboxId, 3)
        ->set('months', 9)
        ->call('submit')
        ->assertStatus(200)
        ->assertSet('errorMessage', Lang::get('email-scan::inboxes.toast.reconnect_first'))
        ->assertNotDispatched('modal-close');

    expect(refusedBackfillWindowMonths($inboxId))->toBe(3);
    Bus::assertNotDispatched(BackfillInboxJob::class);
});

it('answers in the modal and dispatches nothing while a scan is already running', function (string $status): void {
    Bus::fake();
    $user = refusedBackfillUser('backfill-busy-'.$status);
    $inboxId = refusedBackfillInbox($user, $status);
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', $inboxId, 3)
        ->set('months', 9)
        ->call('submit')
        ->assertStatus(200)
        ->assertSet('errorMessage', Lang::get('email-scan::inboxes.toast.scan_in_progress'))
        ->assertNotDispatched('modal-close');

    expect(refusedBackfillWindowMonths($inboxId))->toBe(3);
    Bus::assertNotDispatched(BackfillInboxJob::class);
})->with([
    InboxScanStatus::Scanning->value,
    InboxScanStatus::Backfilling->value,
]);

// The control the three refusals are worth nothing without: an inbox in a
// state the job can start from still starts, with the window the slider named.
it('still starts the backfill from a state the job can enter', function (): void {
    Bus::fake();
    $user = refusedBackfillUser('backfill-idle');
    $inboxId = refusedBackfillInbox($user, InboxScanStatus::Idle->value);
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', $inboxId, 3)
        ->set('months', 9)
        ->call('submit')
        ->assertSet('errorMessage', '')
        ->assertDispatched('modal-close');

    expect(refusedBackfillWindowMonths($inboxId))->toBe(9);
    Bus::assertDispatched(BackfillInboxJob::class, static fn (BackfillInboxJob $job): bool => $job->windowMonths === 9);
});

// Defence in depth behind the modal: a dispatch that wins the race against a
// mailbox falling into needs_reauth still refuses, and now says so somewhere.
it('logs the refusal when the job itself cannot enter backfilling', function (): void {
    $user = refusedBackfillUser('backfill-job-refusal');
    $inboxId = refusedBackfillInbox($user, InboxScanStatus::NeedsReauth->value);

    $recorder = refusedBackfillRecorder();
    $this->app->instance(LoggerInterface::class, $recorder);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    $warnings = array_values(array_filter(
        $recorder->records,
        static fn (array $record): bool => $record['level'] === 'warning',
    ));

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['message'])->toContain('BackfillInboxJob');
    expect($warnings[0]['context']['inbox_id'])->toBe($inboxId);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->value('status'))
        ->toBe(InboxScanStatus::NeedsReauth->value);
});
