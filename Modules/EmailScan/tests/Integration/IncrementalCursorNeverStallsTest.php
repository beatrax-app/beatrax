<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Exceptions\InboxNotConfiguredException;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Public\Enums\InboxScanStatus;

uses(RefreshDatabase::class);

// The cursor is the only thing that moves a Gmail inbox forward. Anything that
// aborts the tick before it is written makes the next tick re-read the same
// history, meet the same message, and abort again — for good.

beforeEach(function (): void {
    Sleep::fake();

    $this->inboxRoot = storage_path('app/inbox');
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

afterEach(function (): void {
    if (is_dir($this->inboxRoot)) {
        $this->app->make(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

function seedGmailInboxForCursorTest(string $username): array
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture',
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
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => InboxScanStatus::Idle->value,
        'last_history_id' => '12345',
        'last_scan_at' => $now,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$db, $inboxId];
}

it('advances the gmail cursor past a message the provider will not hand over', function (): void {
    [$db, $inboxId] = seedGmailInboxForCursorTest('stall01');

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->queueHistoryResponse(['paypal-sample-receipt', 'ics-sample-statement-notice'], '12400');
    $fake->simulateMissingMessage('paypal-sample-receipt');
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    $state = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'last_history_id']);

    expect($state)->not->toBeNull();
    expect($state->last_history_id)->toBe('12400');
    expect($state->status)->toBe(InboxScanStatus::Idle->value);

    // The reachable sibling still lands: one unavailable id must not cost the
    // rest of the batch.
    $landed = $db->connection()
        ->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->pluck('provider_message_id')
        ->all();

    expect($landed)->toBe(['ics-sample-statement-notice']);
});

it('leaves an inbox with no persisted OAuth credentials in needs_reauth instead of handing it back to the queue', function (): void {
    [$db, $inboxId] = seedGmailInboxForCursorTest('unconf01');

    $unconfigured = new class implements GmailApiClientContract
    {
        /**
         * @param  list<string>  $senderPatterns
         * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string, resultSizeEstimate: int}
         */
        public function listSenderMessages(
            int $inboxId,
            array $senderPatterns,
            ?string $pageToken,
            ?DateTimeImmutable $windowStart = null,
        ): array {
            throw $this->unconfigured($inboxId);
        }

        public function currentHistoryId(int $inboxId): ?string
        {
            throw $this->unconfigured($inboxId);
        }

        public function getRawMessage(int $inboxId, string $providerMessageId): string
        {
            throw $this->unconfigured($inboxId);
        }

        /**
         * @return array{history: list<array<string, mixed>>, historyId: ?string}
         */
        public function listHistory(int $inboxId, string $startHistoryId): array
        {
            throw $this->unconfigured($inboxId);
        }

        /**
         * @param  list<string>  $keywords
         * @param  list<string>  $excludeSenders
         * @return array{messages: list<array<string, mixed>>, nextPageToken: ?string}
         */
        public function listDiscoveryCandidates(
            int $inboxId,
            array $keywords,
            array $excludeSenders,
            ?string $pageToken = null,
        ): array {
            throw $this->unconfigured($inboxId);
        }

        private function unconfigured(int $inboxId): InboxNotConfiguredException
        {
            return new InboxNotConfiguredException(
                "GmailApiClient: no OAuth credentials persisted for inbox {$inboxId}.",
            );
        }
    };

    $this->app->instance(GmailApiClientContract::class, $unconfigured);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);

    // No throw: re-throwing is what schedules the retry, and no later attempt
    // can conjure a credential the wizard never wrote.
    $this->app->call([$job, 'handle']);

    $state = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status']);

    expect($state)->not->toBeNull();
    expect($state->status)->toBe(InboxScanStatus::NeedsReauth->value);
});
