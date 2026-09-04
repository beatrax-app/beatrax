<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\BoundedReadException;
use Modules\Core\Public\Support\UploadLimits;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Clients\RateLimitedException;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

uses(RefreshDatabase::class);

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

function emailScanWalkMessage(string $id, string $address, string $received): array
{
    return [
        'id' => $id,
        'subject' => 'Receipt',
        'receivedDateTime' => $received,
        'from' => ['emailAddress' => ['name' => 'Sender', 'address' => $address]],
    ];
}

function emailScanWalkInbox(string $username): array
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
        'provider' => 'microsoft',
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
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$user, $inboxId, $db];
}

// Both providers legitimately answer an empty page while still handing back a
// link to the next one. Reading the emptiness as "walk finished" drops every
// message past that point without a trace.
it('keeps walking past an empty page that still carries a next link', function (): void {
    [$user, $inboxId, $db] = emailScanWalkInbox('walk-empty-page');

    $fake = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fake->queueSenderPage(
        [emailScanWalkMessage('paypal-a', 'service@paypal.com', '2026-05-11T09:14:21Z')],
        nextLink: 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2',
    );
    $fake->queueSenderPage([], nextLink: 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p3');
    $fake->queueSenderPage([emailScanWalkMessage('ics-c', 'noreply@ics.nl', '2026-05-12T06:00:13Z')], nextLink: null);
    $this->app->instance(GraphApiClientContract::class, $fake);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    $stored = $db->connection()->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->orderBy('provider_message_id')
        ->pluck('provider_message_id')
        ->all();

    $listCursors = array_values(array_map(
        static fn (array $c): mixed => $c['args']['nextLink'],
        array_filter($fake->getRequestedCalls(), static fn (array $c): bool => $c['method'] === 'listSenderMessagesPaged'),
    ));

    expect($stored)->toBe(['ics-c', 'paypal-a'])
        ->and($listCursors)->toBe([
            null,
            'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2',
            'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p3',
        ]);
});

// A retry that restarts at page one re-walks every page the first attempt
// already paid for, and the progress bar runs backwards because the counter
// only ever saw the rows THIS attempt inserted.
it('resumes a retried backfill from the page it stopped on and keeps the progress count', function (): void {
    [$user, $inboxId, $db] = emailScanWalkInbox('walk-resume');

    $attemptOne = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $attemptOne->queueSenderPage(
        [emailScanWalkMessage('paypal-a', 'service@paypal.com', '2026-05-11T09:14:21Z')],
        nextLink: 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2',
    );
    $attemptOne->queueSenderPageRateLimit(30);
    $this->app->instance(GraphApiClientContract::class, $attemptOne);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    expect(fn () => $this->app->call([$job, 'handle']))->toThrow(RateLimitedException::class);

    $progress = json_decode((string) $db->connection()->table('inboxes')->where('id', $inboxId)->value('backfill_progress'), true);
    expect($progress['fetched_count'])->toBe(1)
        ->and($progress['page_cursor'])->toBe('https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2');

    $attemptTwo = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $attemptTwo->queueSenderPage([emailScanWalkMessage('ics-c', 'noreply@ics.nl', '2026-05-12T06:00:13Z')], nextLink: null);
    $this->app->instance(GraphApiClientContract::class, $attemptTwo);

    /** @var BackfillInboxJob $retry */
    $retry = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$retry, 'handle']);

    $listCursors = array_values(array_map(
        static fn (array $c): mixed => $c['args']['nextLink'],
        array_filter($attemptTwo->getRequestedCalls(), static fn (array $c): bool => $c['method'] === 'listSenderMessagesPaged'),
    ));

    $stored = $db->connection()->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->orderBy('provider_message_id')
        ->pluck('provider_message_id')
        ->all();

    expect($listCursors)->toBe(['https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2'])
        ->and($stored)->toBe(['ics-c', 'paypal-a']);
});

// The counter has to answer "how many of this backfill's messages are
// indexed", not "how many rows did this attempt insert" — a re-walked page
// inserts nothing and used to drag the number back to zero.
it('counts an already-indexed message towards the progress total', function (): void {
    [$user, $inboxId, $db] = emailScanWalkInbox('walk-count');

    $first = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $first->queueSenderPage([emailScanWalkMessage('paypal-a', 'service@paypal.com', '2026-05-11T09:14:21Z')], nextLink: null);
    $this->app->instance(GraphApiClientContract::class, $first);
    $this->app->call([$this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]), 'handle']);

    $second = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $second->queueSenderPage(
        [emailScanWalkMessage('paypal-a', 'service@paypal.com', '2026-05-11T09:14:21Z')],
        nextLink: 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2',
    );
    $second->queueSenderPageRateLimit(30);
    $this->app->instance(GraphApiClientContract::class, $second);

    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    expect(fn () => $this->app->call([$job, 'handle']))->toThrow(RateLimitedException::class);

    $progress = json_decode((string) $db->connection()->table('inboxes')->where('id', $inboxId)->value('backfill_progress'), true);

    expect($progress['fetched_count'])->toBe(1);
});

// A message this device will not hold whole has to cost that one message.
// Letting the refusal out of the walk abandoned every page behind it and put
// the whole inbox into error over a single item.
it('skips a message the ceiling refuses and keeps walking the rest of the page', function (): void {
    [$user, $inboxId, $db] = emailScanWalkInbox('walk-oversized');

    $refuser = new class implements GraphApiClientContract
    {
        public function listSenderMessagesPaged(int $inboxId, array $senderPatterns, DateTimeImmutable $windowStart, ?string $nextLink): array
        {
            return [
                'messages' => [
                    emailScanWalkMessage('paypal-before', 'service@paypal.com', '2026-05-11T09:14:21Z'),
                    emailScanWalkMessage('paypal-oversized', 'service@paypal.com', '2026-05-11T09:15:21Z'),
                    emailScanWalkMessage('paypal-after', 'service@paypal.com', '2026-05-11T09:16:21Z'),
                ],
                'nextLink' => null,
            ];
        }

        public function getRawMessage(int $inboxId, string $providerMessageId): string
        {
            if ($providerMessageId === 'paypal-oversized') {
                throw BoundedReadException::tooLarge('Graph message '.$providerMessageId, 26 * 1024 * 1024, UploadLimits::MAX_MESSAGE_BYTES);
            }

            return "From: service@paypal.com\r\nTo: cardholder@example.test\r\nSubject: Receipt\r\n"
                ."Date: Mon, 11 May 2026 09:14:21 +0000\r\nMessage-ID: <{$providerMessageId}@paypal.com>\r\n\r\nBody.";
        }

        public function deltaPage(int $inboxId, ?string $deltaLink, ?DateTimeImmutable $sinceOverride = null): array
        {
            return ['messages' => [], 'deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=D', 'nextLink' => null];
        }

        public function listDiscoveryCandidatesPaged(int $inboxId, array $keywords, array $excludeSenders, ?string $nextLink): array
        {
            return ['messages' => [], 'nextLink' => null];
        }
    };
    $this->app->instance(GraphApiClientContract::class, $refuser);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    $stored = $db->connection()->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->orderBy('provider_message_id')
        ->pluck('provider_message_id')
        ->all();

    expect($stored)->toBe(['paypal-after', 'paypal-before'])
        ->and($db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->value('status'))->toBe('idle');
});

// Belt and braces against a provider that answers every page with another
// nextLink: without a ceiling the walk is a `while (true)`.
it('stops walking at the page ceiling instead of paging forever', function (): void {
    [$user, $inboxId, $db] = emailScanWalkInbox('walk-runaway');

    $runaway = new class implements GraphApiClientContract
    {
        public int $pagesServed = 0;

        public function listSenderMessagesPaged(int $inboxId, array $senderPatterns, DateTimeImmutable $windowStart, ?string $nextLink): array
        {
            $this->pagesServed++;
            if ($this->pagesServed > 1000) {
                throw new RuntimeException('The walk never stopped asking for another page.');
            }

            return [
                'messages' => [],
                'nextLink' => 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=page'.$this->pagesServed,
            ];
        }

        public function getRawMessage(int $inboxId, string $providerMessageId): string
        {
            return '';
        }

        public function deltaPage(int $inboxId, ?string $deltaLink, ?DateTimeImmutable $sinceOverride = null): array
        {
            return ['messages' => [], 'deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=D', 'nextLink' => null];
        }

        public function listDiscoveryCandidatesPaged(int $inboxId, array $keywords, array $excludeSenders, ?string $nextLink): array
        {
            return ['messages' => [], 'nextLink' => null];
        }
    };
    $this->app->instance(GraphApiClientContract::class, $runaway);

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$job, 'handle']);

    expect($runaway->pagesServed)->toBeLessThanOrEqual(200);
});
