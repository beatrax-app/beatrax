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
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// runGmailIncremental advances the cursor outside the message loop, so a
// message dropped inside it is never surfaced by listHistory again. A 404 has
// nothing left to lose; a payload that arrived and would not decode is a
// receipt this device received, could not read, and used to forget in silence.

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

function decodeSkipInbox(string $username): array
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
        'status' => 'idle',
        'last_history_id' => '12345',
        'last_scan_at' => $now,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [$user, $inboxId, $db];
}

function decodeSkipRecorder(): LoggerInterface
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

it('leaves a readable skip behind for a message it received and could not decode', function (): void {
    [, $inboxId, $db] = decodeSkipInbox('decode-skip');

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->queueHistoryResponse(
        ['ics-lost-in-decode', 'ics-sample-statement-notice', 'paypal-sample-receipt'],
        '12400',
    );
    $fake->simulateUndecodableMessage('ics-lost-in-decode');
    $fake->simulateMissingMessage('paypal-sample-receipt');
    $this->app->instance(GmailApiClientContract::class, $fake);

    $recorder = decodeSkipRecorder();
    $this->app->instance(LoggerInterface::class, $recorder);

    /** @var IncrementalScanJob $job */
    $job = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$job, 'handle']);

    $stored = $db->connection()->table('inbox_messages')
        ->where('inbox_id', $inboxId)
        ->orderBy('provider_message_id')
        ->pluck('status', 'provider_message_id')
        ->all();

    // The cursor has moved past all three, which is what makes the skip
    // permanent: listHistory will never name these ids again.
    expect($db->connection()->table('inbox_scan_state')->where('inbox_id', $inboxId)->value('last_history_id'))
        ->toBe('12400');

    expect($stored)->toHaveKey('ics-lost-in-decode');
    expect($stored['ics-lost-in-decode'])->toBe(InboxMessageStatus::Skipped->value);
    expect($stored['ics-sample-statement-notice'])->toBe(InboxMessageStatus::Fetched->value);

    // A message the provider no longer holds is an absence, not a loss, and
    // stays the silent skip it always was.
    expect($stored)->not->toHaveKey('paypal-sample-receipt');

    $warnings = array_values(array_filter(
        $recorder->records,
        static fn (array $record): bool => $record['level'] === 'warning',
    ));

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['context']['provider_message_id'])->toBe('ics-lost-in-decode');
    expect($warnings[0]['context']['inbox_id'])->toBe($inboxId);

    // Nothing about the message may reach the log: the id, the inbox and the
    // failure class are the whole of it.
    expect(array_keys($warnings[0]['context']))->toBe(['inbox_id', 'provider_message_id', 'reason', 'sqlstate']);
});

// The skip is durable, not a note that evaporates: alreadyIndexed() answers on
// it, so a later cursor-expiry fallback walk does not re-spend quota on bytes
// this device has already proved it cannot read.
it('does not fetch a recorded skip again on a later pass', function (): void {
    [, $inboxId, $db] = decodeSkipInbox('decode-skip-again');

    $fake = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fake->queueHistoryResponse(['ics-lost-in-decode'], '12400');
    $fake->simulateUndecodableMessage('ics-lost-in-decode');
    $this->app->instance(GmailApiClientContract::class, $fake);
    $this->app->instance(LoggerInterface::class, decodeSkipRecorder());

    /** @var IncrementalScanJob $first */
    $first = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$first, 'handle']);

    $fake->queueHistoryResponse(['ics-lost-in-decode'], '12500');

    /** @var IncrementalScanJob $second */
    $second = $this->app->make(IncrementalScanJob::class, ['inboxId' => $inboxId]);
    $this->app->call([$second, 'handle']);

    $rawCalls = array_values(array_filter(
        $fake->getRequestedCalls(),
        static fn (array $call): bool => $call['method'] === 'getRawMessage',
    ));

    expect($rawCalls)->toHaveCount(1);
    expect($db->connection()->table('inbox_messages')->where('inbox_id', $inboxId)->count())->toBe(1);
});
