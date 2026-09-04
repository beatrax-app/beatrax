<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Clients\FakeGmailApiClient;
use Modules\EmailScan\Internal\Clients\FakeGraphApiClient;
use Modules\EmailScan\Internal\Clients\GmailApiClientContract;
use Modules\EmailScan\Internal\Clients\GraphApiClientContract;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// A quota is spent per provider credential. The walk used to return from
// handle() on the first inbox that hit one, so a busy Gmail account took the
// Microsoft inbox down with it — and discovered_senders has no per-inbox
// lifecycle column, so nothing anywhere said the mailbox had been skipped.

function throttledScanSpyLogger(): object
{
    return new class extends AbstractLogger
    {
        /** @var list<array{message: string, context: array<mixed>}> */
        public array $records = [];

        /**
         * @param  mixed  $level
         * @param  Stringable|string  $message
         * @param  array<mixed>  $context
         */
        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['message' => (string) $message, 'context' => $context];
        }

        /** @return list<array{message: string, context: array<mixed>}> */
        public function quotaRefusals(): array
        {
            return array_values(array_filter(
                $this->records,
                static fn (array $r): bool => str_contains($r['message'], 'refused on quota'),
            ));
        }
    };
}

function seedScannedInbox(DatabaseManager $db, int $userId, string $provider, string $email): int
{
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $userId,
        'provider' => $provider,
        'email' => $email,
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $userId,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

it('walks the Microsoft inbox after the Gmail one spends its quota, and says the Gmail one was skipped', function (): void {
    $user = User::query()->create([
        'username' => 'throttled-discovery-walk',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Inserted first, so the walk reaches it first: the ordering IS the defect.
    $gmailInboxId = seedScannedInbox($db, (int) $user->id, 'gmail', 'throttled@example.com');
    $graphInboxId = seedScannedInbox($db, (int) $user->id, 'microsoft', 'still-owed@example.com');

    $fakeGmail = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fakeGmail->simulateRateLimit($gmailInboxId);
    $this->app->instance(GmailApiClientContract::class, $fakeGmail);

    $fakeGraph = new FakeGraphApiClient($this->app->make(Filesystem::class));
    $fakeGraph->queueDiscoveryResponse(
        [['id' => 'g-1', 'from' => ['emailAddress' => ['address' => 'billing@microsoft-inbox.example', 'name' => 'Billing']], 'receivedDateTime' => '2026-05-10T00:00:00Z']],
        nextLink: null,
    );
    $this->app->instance(GraphApiClientContract::class, $fakeGraph);

    $spy = throttledScanSpyLogger();
    $this->app->instance(LoggerInterface::class, $spy);

    /** @var DiscoveryScanJob $job */
    $job = $this->app->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    $this->app->call([$job, 'handle']);

    expect($db->connection()->table('discovered_senders')->where('inbox_id', $graphInboxId)->count())
        ->toBeGreaterThan(0, 'a throttled inbox on one provider must not keep another provider from being walked');

    $refusals = $spy->quotaRefusals();

    expect($refusals)->toHaveCount(1)
        ->and($refusals[0]['context'])->toBe([
            'user_id' => (int) $user->id,
            'inbox_id' => $gmailInboxId,
            'provider' => 'gmail',
        ]);
});

it('stops asking a provider that has already refused on quota', function (): void {
    $user = User::query()->create([
        'username' => 'throttled-discovery-same-provider',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $firstInboxId = seedScannedInbox($db, (int) $user->id, 'gmail', 'first@example.com');
    seedScannedInbox($db, (int) $user->id, 'gmail', 'second@example.com');

    $fakeGmail = new FakeGmailApiClient($this->app->make(Filesystem::class));
    $fakeGmail->simulateRateLimit($firstInboxId);
    $this->app->instance(GmailApiClientContract::class, $fakeGmail);
    $this->app->instance(GraphApiClientContract::class, new FakeGraphApiClient($this->app->make(Filesystem::class)));

    $spy = throttledScanSpyLogger();
    $this->app->instance(LoggerInterface::class, $spy);

    /** @var DiscoveryScanJob $job */
    $job = $this->app->make(DiscoveryScanJob::class, ['userId' => $user->id]);
    $this->app->call([$job, 'handle']);

    $asked = array_values(array_filter(
        $fakeGmail->getRequestedCalls(),
        static fn (array $c): bool => $c['method'] === 'listDiscoveryCandidates',
    ));

    expect($asked)->toHaveCount(1, 'the second inbox shares the refused credential, so asking again only spends the retry')
        ->and($spy->quotaRefusals())->toHaveCount(1);
});
