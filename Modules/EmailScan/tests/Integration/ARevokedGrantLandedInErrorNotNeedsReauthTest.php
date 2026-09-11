<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Internal\OAuth\ReconsentRequiredException;
use Modules\EmailScan\Public\Enums\InboxScanStatus;

uses(RefreshDatabase::class);

// The reader revoking the app at their provider is the ordinary way a grant
// ends, and the refresh call is where it surfaces. Landed on `error`, the
// scheduler's `!= needs_reauth` filter does not skip the inbox, so every tick
// spends another refresh against a grant that is gone -- and no screen offers
// the one thing that fixes it.
beforeEach(function (): void {
    Sleep::fake();
});

function seedRevokedGmailInbox(): array
{
    $user = User::query()->create([
        'username' => 'revoked01',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'revoked01@example.com',
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

    return [$db, $inboxId, $user->id];
}

function revokedGmailClient(int $inboxId, int $userId): GmailApiClientContract
{
    return new class($inboxId, $userId) implements GmailApiClientContract
    {
        public function __construct(private int $inboxId, private int $userId) {}

        /**
         * @param  list<string>  $senderPatterns
         * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string, resultSizeEstimate: int}
         */
        public function listSenderMessages(int $inboxId, array $senderPatterns, ?string $pageToken, ?DateTimeImmutable $windowStart = null): array
        {
            throw $this->revoked();
        }

        public function currentHistoryId(int $inboxId): ?string
        {
            throw $this->revoked();
        }

        public function getRawMessage(int $inboxId, string $providerMessageId): string
        {
            throw $this->revoked();
        }

        /**
         * @return array{history: list<array<string, mixed>>, historyId: ?string}
         */
        public function listHistory(int $inboxId, string $startHistoryId): array
        {
            throw $this->revoked();
        }

        /**
         * @param  list<string>  $keywords
         * @param  list<string>  $excludeSenders
         * @return array{messages: list<array<string, mixed>>, nextPageToken: ?string}
         */
        public function listDiscoveryCandidates(int $inboxId, array $keywords, array $excludeSenders, ?string $pageToken = null): array
        {
            throw $this->revoked();
        }

        private function revoked(): ReconsentRequiredException
        {
            return new ReconsentRequiredException($this->inboxId, $this->userId, 'gmail');
        }
    };
}

it('moves an inbox whose grant was revoked to needs_reauth rather than error', function (): void {
    [$db, $inboxId, $userId] = seedRevokedGmailInbox();
    $this->app->instance(GmailApiClientContract::class, revokedGmailClient($inboxId, $userId));

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    $status = $db->connection()
        ->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->value('status');

    expect($status)->toBe(InboxScanStatus::NeedsReauth->value);
});

it('does not hand a revoked grant back to the queue for another refresh', function (): void {
    [, $inboxId, $userId] = seedRevokedGmailInbox();
    $this->app->instance(GmailApiClientContract::class, revokedGmailClient($inboxId, $userId));

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);

    expect(fn (): mixed => $this->app->call([$job, 'handle']))->not->toThrow(ReconsentRequiredException::class);
});
