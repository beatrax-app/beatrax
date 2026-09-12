<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

uses(RefreshDatabase::class);

// The walk persists its page cursor so a retry does not re-walk the pages the
// last attempt paid for. It persisted it into `inboxes.backfill_progress`,
// which `applyStatus()` nulls on every transition out of flight — and the
// error transition is the one a retry rides. So the statement that stopped a
// dead backfill advertising a stale count also erased the resume point, and
// every attempt after an error started again at page one. The rate-limited
// arm kept its progress, which is why the resume that was tested worked.

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

/**
 * @return array<string, mixed>
 */
function errorResumeMessage(string $id, string $received): array
{
    return [
        'id' => $id,
        'subject' => 'Receipt',
        'receivedDateTime' => $received,
        'from' => ['emailAddress' => ['name' => 'Sender', 'address' => 'service@paypal.com']],
    ];
}

/**
 * @return array{0: User, 1: int, 2: DatabaseManager}
 */
function errorResumeInbox(string $username): array
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

function errorResumeRefusingClient(string $nextLink): GraphApiClientContract
{
    return new class($nextLink) implements GraphApiClientContract
    {
        public int $pagesServed = 0;

        public function __construct(private readonly string $nextLink) {}

        public function listSenderMessagesPaged(int $inboxId, array $senderPatterns, DateTimeImmutable $windowStart, ?string $nextLink): array
        {
            $this->pagesServed++;

            if ($this->pagesServed > 1) {
                throw new RuntimeException('the provider answered the second page with a 500');
            }

            return [
                'messages' => [errorResumeMessage('paypal-page-one', '2026-05-11T09:14:21Z')],
                'nextLink' => $this->nextLink,
            ];
        }

        public function getRawMessage(int $inboxId, string $providerMessageId): string
        {
            return "From: service@paypal.com\r\nTo: cardholder@example.test\r\nSubject: Receipt\r\n"
                ."Date: Mon, 11 May 2026 09:14:21 +0000\r\nMessage-ID: <{$providerMessageId}@paypal.com>\r\n\r\nBody.";
        }

        public function deltaPage(int $inboxId, ?string $deltaLink, ?DateTimeImmutable $sinceOverride = null): array
        {
            return ['messages' => [], 'deltaLink' => null, 'nextLink' => null];
        }

        public function listDiscoveryCandidatesPaged(int $inboxId, array $keywords, array $excludeSenders, ?string $nextLink): array
        {
            return ['messages' => [], 'nextLink' => null];
        }
    };
}

it('resumes on the page an error stopped it on, with the strip still cleared', function (): void {
    [$user, $inboxId, $db] = errorResumeInbox('error-resume');
    $secondPage = 'https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2';

    $this->app->instance(GraphApiClientContract::class, errorResumeRefusingClient($secondPage));

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    expect(fn () => $this->app->call([$job, 'handle']))->toThrow(RuntimeException::class);

    $state = $db->connection()->table('inbox_scan_state')
        ->where('inbox_id', $inboxId)
        ->where('folder', 'INBOX')
        ->first(['status', 'backfill_resume_point']);
    $resumePoint = json_decode((string) $state->backfill_resume_point, true);

    // The count the reader sees is gone, because the backfill that was feeding
    // it is: that is what the error transition is for. The page it stopped on
    // is not, because the next attempt needs it.
    expect($db->connection()->table('inboxes')->where('id', $inboxId)->value('backfill_progress'))->toBeNull()
        ->and($state->status)->toBe('error')
        ->and($resumePoint['page_cursor'])->toBe($secondPage)
        ->and($resumePoint['fetched_count'])->toBe(1)
        ->and($resumePoint['window_months'])->toBe(3);

    $retryClient = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $retryClient->queueSenderPage([errorResumeMessage('paypal-page-two', '2026-05-12T06:00:13Z')], nextLink: null);
    $this->app->instance(GraphApiClientContract::class, $retryClient);

    /** @var BackfillInboxJob $retry */
    $retry = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    $this->app->call([$retry, 'handle']);

    $walked = array_values(array_map(
        static fn (array $call): mixed => $call['args']['nextLink'],
        array_filter(
            $retryClient->getRequestedCalls(),
            static fn (array $call): bool => $call['method'] === 'listSenderMessagesPaged',
        ),
    ));

    $stored = $db->connection()->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->orderBy('provider_message_id')
        ->pluck('provider_message_id')
        ->all();

    expect($walked)->toBe([$secondPage])
        ->and($stored)->toBe(['paypal-page-one', 'paypal-page-two'])
        ->and($db->connection()->table('inbox_scan_state')
            ->where('inbox_id', $inboxId)
            ->value('backfill_resume_point'))->toBeNull();
});

it('drops the resume point once the attempts are spent', function (): void {
    [$user, $inboxId, $db] = errorResumeInbox('error-spent');
    $this->app->instance(
        GraphApiClientContract::class,
        errorResumeRefusingClient('https://graph.microsoft.com/v1.0/me/messages?$skiptoken=p2'),
    );

    /** @var BackfillInboxJob $job */
    $job = $this->app->make(BackfillInboxJob::class, ['inboxId' => $inboxId, 'windowMonths' => 3]);
    expect(fn () => $this->app->call([$job, 'handle']))->toThrow(RuntimeException::class);
    expect($db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->value('backfill_resume_point'))
        ->not->toBeNull();

    $job->failed(new RuntimeException('the last attempt failed too'));

    expect($db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->value('backfill_resume_point'))
        ->toBeNull()
        ->and($db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->value('status'))->toBe('error');
});
