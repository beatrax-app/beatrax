<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\InboxMessageQuery;
use Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob;
use Modules\Receipts\Internal\ReceiptLedgerBridge;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Tests\Doubles\FakeInboxMessageQuery;
use Psr\Log\LoggerInterface;

// The handoff ImportRun is anchored on a sha256 of user + hour, and import_runs
// is UNIQUE (user_id, sha256). Two dispatches inside one hour — a retry, a
// manual dispatch, a queue redelivery — therefore re-enter the same anchor,
// each carrying its own new receipt.

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];

    $this->app->instance(Clock::class, new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-05-17 09:45:00');
        }
    });

    DB::table('inboxes')->insert([
        'user_id' => $this->fixtureUser->id,
        'provider' => 'gmail',
        'email' => 'cardholder@gmail.test',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
    $inboxId = (int) DB::table('inboxes')->where('user_id', $this->fixtureUser->id)->value('id');

    $seed = function (string $providerMessageId, string $fixture, string $isoInstant) use ($inboxId): InboxMessageDto {
        $internalDate = new DateTimeImmutable($isoInstant);
        $stamp = $internalDate->format('Y-m-d H:i:s');
        DB::table('inbox_messages')->insert([
            'user_id' => $this->fixtureUser->id,
            'inbox_id' => $inboxId,
            'provider_message_id' => $providerMessageId,
            'internal_date' => $stamp,
            'sender_email' => 'service@paypal.com',
            'sender_name' => null,
            'subject' => 'Je ontvangstbewijs',
            'status' => 'fetched',
            'matcher_key' => null,
            'fetched_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
        $rowId = (int) DB::table('inbox_messages')
            ->where('user_id', $this->fixtureUser->id)
            ->where('provider_message_id', $providerMessageId)
            ->value('id');

        /** @var EmlBlobStore $blobs */
        $blobs = app(EmlBlobStore::class);
        $blobs->put(
            $blobs->pathFor($this->fixtureUser->id, $inboxId, $internalDate, $providerMessageId),
            (string) file_get_contents(__DIR__.'/../fixtures/paypal/'.$fixture),
        );

        return new InboxMessageDto(
            id: $rowId,
            userId: $this->fixtureUser->id,
            inboxId: $inboxId,
            providerMessageId: $providerMessageId,
            internalDate: $internalDate,
            senderEmail: 'service@paypal.com',
            senderName: null,
            subject: 'Je ontvangstbewijs',
            status: 'fetched',
            fetchedAt: $internalDate,
        );
    };

    $this->firstBatch = $seed('rcpt-hour-a', 'current-receipt.eml', '2026-05-17T09:42:13+02:00');
    $this->secondBatch = $seed('rcpt-hour-b', 'foreign-currency-receipt.eml', '2026-05-17T09:44:57+02:00');

    $this->runJobOver = function (InboxMessageDto $message): void {
        $this->app->instance(
            InboxMessageQuery::class,
            new FakeInboxMessageQuery([$message], $this->app->make(DatabaseManager::class)),
        );

        $job = new ProcessFetchedInboxMessagesJob($this->fixtureUser->id);
        $job->handle(
            $this->app->make(DatabaseManager::class),
            $this->app->make(Clock::class),
            $this->app->make(Filesystem::class),
            $this->app->make(InboxMessageQuery::class),
            $this->app->make(EmlBlobStore::class),
            $this->app->make(RecordReceipt::class),
            $this->app->make(ReceiptLedgerBridge::class),
            $this->app->make(LoggerInterface::class),
        );
    };
});

it('does not crash on the unique user_id+sha256 index when a second dispatch lands inside the same hour', function (): void {
    ($this->runJobOver)($this->firstBatch);
    ($this->runJobOver)($this->secondBatch);
})->throwsNoExceptions();

it('collapses two dispatches inside one hour onto a single inbox-handoff ImportRun instead of inserting a second row', function (): void {
    ($this->runJobOver)($this->firstBatch);
    ($this->runJobOver)($this->secondBatch);

    $runs = DB::table('import_runs')
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'inbox-handoff')
        ->get();

    expect($runs)->toHaveCount(1);
    expect($runs[0]->status)->toBe('confirmed');
});

it('files both hours-worth of bridged receipts under the one adopted ImportRun', function (): void {
    ($this->runJobOver)($this->firstBatch);
    ($this->runJobOver)($this->secondBatch);

    $runId = DB::table('import_runs')
        ->where('user_id', $this->fixtureUser->id)
        ->where('source_format', 'inbox-handoff')
        ->value('id');

    $rows = DB::table('transactions')
        ->where('user_id', $this->fixtureUser->id)
        ->where('import_run_id', $runId)
        ->get();

    expect($rows)->toHaveCount(2);
});

it('stamps the bridged receipt transaction with the eml source format rather than a bare literal', function (): void {
    ($this->runJobOver)($this->firstBatch);

    $formats = DB::table('transactions')
        ->where('user_id', $this->fixtureUser->id)
        ->pluck('source_format')
        ->all();

    expect($formats)->toBe(['eml']);
});
