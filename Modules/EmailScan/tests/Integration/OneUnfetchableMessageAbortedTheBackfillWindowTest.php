<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\InboxMessageStatus;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\MessageUnavailableException;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;
use Modules\EmailScan\Public\Enums\InboxScanStatus;

uses(RefreshDatabase::class);

// storeOrSkip() caught BoundedReadException and nothing else, so anything else
// one message threw left walkAndPersist entirely. With tries exhausted the job
// simply failed, and every page after the one it stopped on was never fetched
// -- a backfill the reader asked for over a year of mail, ended on message two.

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

function backfillWindowGraphClient(FakeGraphApiClient $inner, string $messageId, Throwable $failure): GraphApiClientContract
{
    return new class($inner, $messageId, $failure) implements GraphApiClientContract
    {
        public function __construct(
            private FakeGraphApiClient $inner,
            private string $messageId,
            private Throwable $failure,
        ) {}

        /**
         * @param  list<string>  $senderPatterns
         * @return array{messages: list<array<string, mixed>>, nextLink: ?string}
         */
        public function listSenderMessagesPaged(int $inboxId, array $senderPatterns, DateTimeImmutable $windowStart, ?string $nextLink): array
        {
            return $this->inner->listSenderMessagesPaged($inboxId, $senderPatterns, $windowStart, $nextLink);
        }

        public function getRawMessage(int $inboxId, string $providerMessageId): string
        {
            if ($providerMessageId === $this->messageId) {
                throw $this->failure;
            }

            return $this->inner->getRawMessage($inboxId, $providerMessageId);
        }

        /**
         * @return array{messages: list<array<string, mixed>>, deltaLink: ?string, nextLink: ?string}
         */
        public function deltaPage(int $inboxId, ?string $deltaLink, ?DateTimeImmutable $sinceOverride = null): array
        {
            return $this->inner->deltaPage($inboxId, $deltaLink, $sinceOverride);
        }

        /**
         * @param  list<string>  $keywords
         * @param  list<string>  $excludeSenders
         * @return array{messages: list<array<string, mixed>>, nextLink: ?string}
         */
        public function listDiscoveryCandidatesPaged(int $inboxId, array $keywords, array $excludeSenders, ?string $nextLink): array
        {
            return $this->inner->listDiscoveryCandidatesPaged($inboxId, $keywords, $excludeSenders, $nextLink);
        }
    };
}

function backfillWindowInbox(string $username, string $provider): array
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
        'status' => InboxScanStatus::Idle->value,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$user, $inboxId, $db];
}

function backfillWindowStatuses(DatabaseManager $db, int $inboxId): array
{
    return $db->connection()->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->orderBy('provider_message_id')
        ->pluck('status', 'provider_message_id')
        ->all();
}

it('fetches the rest of the window past a message the provider no longer holds', function (): void {
    [, $inboxId, $db] = backfillWindowInbox('backfill-gone', 'microsoft');

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(
        GraphApiClientContract::class,
        backfillWindowGraphClient($fake, 'ics-sample-statement-notice', new MessageUnavailableException('Microsoft Graph no longer holds the message: ErrorItemNotFound')),
    );

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['status', 'last_delta_link']);

    // googleplay is the message AFTER the one that could not be fetched: it is
    // the whole rest of the window, standing in for the other eleven months.
    expect(array_keys(backfillWindowStatuses($db, $inboxId)))->toBe(['googleplay-sample-purchase', 'paypal-sample-receipt'])
        ->and($state->status)->toBe(InboxScanStatus::Idle->value)
        ->and($state->last_delta_link)->toBe('https://graph.microsoft.com/v1.0/me/messages/$delta?$deltatoken=baseline-xyz');
});

it('records the skip for a message whose bytes arrived and would not decode, and walks on', function (): void {
    [, $inboxId, $db] = backfillWindowInbox('backfill-undecodable', 'gmail');

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->simulateUndecodableMessage('ics-sample-statement-notice');
    $this->app->instance(GmailApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    $statuses = backfillWindowStatuses($db, $inboxId);
    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['status', 'last_history_id']);

    // Bytes received and unreadable are a loss, not an absence, so the skip is
    // a row a reader can be shown rather than a message that was never here.
    expect($statuses['ics-sample-statement-notice'])->toBe(InboxMessageStatus::Skipped->value)
        ->and($statuses['googleplay-sample-purchase'])->toBe(InboxMessageStatus::Fetched->value)
        ->and($statuses['paypal-sample-receipt'])->toBe(InboxMessageStatus::Fetched->value)
        ->and($state->status)->toBe(InboxScanStatus::Idle->value)
        ->and($state->last_history_id)->toBe('12345');
});

// The positive control for both assertions above. A throttled fetch is the
// whole walk's condition, not this message's, and skipping past it would spend
// the window on refusals -- so the walk must stop here, and this proves the
// "the rest of the window was fetched" assertions can see it when it does.
it('stops the window when the failure is one the whole walk shares', function (): void {
    [, $inboxId, $db] = backfillWindowInbox('backfill-throttled', 'microsoft');

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $this->app->instance(
        GraphApiClientContract::class,
        backfillWindowGraphClient($fake, 'ics-sample-statement-notice', new RateLimitedException(90, 'Application is over its quota.')),
    );

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);

    expect(fn (): mixed => $this->app->call([$job, 'handle']))->toThrow(RateLimitedException::class);

    $state = $db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->first(['status']);

    expect(array_keys(backfillWindowStatuses($db, $inboxId)))->toBe(['paypal-sample-receipt'])
        ->and($state->status)->toBe(InboxScanStatus::RateLimited->value);
});
