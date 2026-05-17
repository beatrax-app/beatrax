<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\InboxMessageQuery;
use Modules\Import\Public\Pipeline\NormalizeStage;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\RecordsTransactions;
use Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;
use Modules\Receipts\Tests\Doubles\FakeInboxMessageQuery;

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];

    $this->seedInboxRowAndBlob = function (int $userId, int $inboxId, string $providerMessageId, string $senderEmail, string $subject, DateTimeImmutable $internalDate, string $bodyBytes): InboxMessageDto {
        /** @var DatabaseManager $db */
        $db = app(DatabaseManager::class);
        $now = $internalDate->format('Y-m-d H:i:s');
        $db->connection()->table('inbox_messages')->insert([
            'user_id' => $userId,
            'inbox_id' => $inboxId,
            'provider_message_id' => $providerMessageId,
            'internal_date' => $now,
            'sender_email' => $senderEmail,
            'sender_name' => null,
            'subject' => $subject,
            'status' => 'fetched',
            'matcher_key' => null,
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var EmlBlobStore $blobs */
        $blobs = app(EmlBlobStore::class);
        $path = $blobs->pathFor($userId, $inboxId, $internalDate, $providerMessageId);
        $blobs->put($path, $bodyBytes);

        $rowId = (int) $db->connection()->table('inbox_messages')
            ->where('user_id', $userId)
            ->where('provider_message_id', $providerMessageId)
            ->value('id');

        return new InboxMessageDto(
            id: $rowId,
            userId: $userId,
            inboxId: $inboxId,
            providerMessageId: $providerMessageId,
            internalDate: $internalDate,
            senderEmail: $senderEmail,
            senderName: null,
            subject: $subject,
            status: 'fetched',
            fetchedAt: $internalDate,
        );
    };
});

it('records uniqueId equal to the userId string form', function (): void {
    $job = new ProcessFetchedInboxMessagesJob($this->fixtureUser->id);
    expect($job->uniqueId())->toBe((string) $this->fixtureUser->id);
});

it('transitions one PayPal receipt to parsed, one login notice to skipped, one unknown sender to unmatched', function (): void {
    DB::table('inboxes')->insert([
        'user_id' => $this->fixtureUser->id,
        'provider' => 'gmail',
        'email' => 'cardholder@gmail.test',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
    $inboxId = (int) DB::table('inboxes')->where('user_id', $this->fixtureUser->id)->value('id');

    $paypalReceiptBytes = (string) file_get_contents(__DIR__.'/../fixtures/paypal/current-receipt.eml');
    $loginBytes = (string) file_get_contents(__DIR__.'/../fixtures/paypal/login-notification.eml');
    $unknownBytes = "From: notifications@netflix.com\r\nTo: kaarthouder@example.test\r\nSubject: Your bill\r\nDate: Sun, 17 May 2026 12:00:00 +0200\r\nMessage-ID: <unk-1@netflix.com>\r\n\r\nBody.";

    $seed = $this->seedInboxRowAndBlob;
    $row1 = $seed($this->fixtureUser->id, $inboxId, 'rcpt-1', 'service@paypal.com', 'Je ontvangstbewijs', new DateTimeImmutable('2026-05-17T09:42:13+02:00'), $paypalReceiptBytes);
    $row2 = $seed($this->fixtureUser->id, $inboxId, 'rcpt-2', 'service@paypal.com', 'New device sign-in', new DateTimeImmutable('2026-05-17T10:00:00+02:00'), $loginBytes);
    $row3 = $seed($this->fixtureUser->id, $inboxId, 'rcpt-3', 'notifications@netflix.com', 'Your bill', new DateTimeImmutable('2026-05-17T12:00:00+02:00'), $unknownBytes);

    $this->app->instance(
        InboxMessageQuery::class,
        new FakeInboxMessageQuery([$row1, $row2, $row3], $this->app->make(DatabaseManager::class)),
    );

    $job = new ProcessFetchedInboxMessagesJob($this->fixtureUser->id);
    $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        $this->app->make(Filesystem::class),
        $this->app->make(InboxMessageQuery::class),
        $this->app->make(EmlBlobStore::class),
        $this->app->make(RecordReceipt::class),
        $this->app->make(ReceiptSourceAdapter::class),
        $this->app->make(NormalizeStage::class),
        $this->app->make(RecordsTransactions::class),
    );

    $rows = DB::table('inbox_messages')->where('user_id', $this->fixtureUser->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(3);
    expect($rows[0]->status)->toBe('parsed');
    expect($rows[0]->matcher_key)->toBe('paypal-receipt');
    expect($rows[1]->status)->toBe('skipped');
    expect($rows[2]->status)->toBe('unmatched');

    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
});

it('does not touch rows whose userId mismatches the job target (T-07-09 cross-user defence)', function (): void {
    DB::table('inboxes')->insert([
        'user_id' => $this->fixtureUser->id,
        'provider' => 'gmail',
        'email' => 'cardholder@gmail.test',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
    $inboxId = (int) DB::table('inboxes')->where('user_id', $this->fixtureUser->id)->value('id');

    $otherUser = User::query()->create([
        'email' => 'other@diederik.test',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $paypalReceiptBytes = (string) file_get_contents(__DIR__.'/../fixtures/paypal/current-receipt.eml');
    $seed = $this->seedInboxRowAndBlob;
    $otherRow = $seed($otherUser->id, $inboxId, 'rcpt-other', 'service@paypal.com', 'Receipt', new DateTimeImmutable('2026-05-17T09:42:13+02:00'), $paypalReceiptBytes);

    $this->app->instance(
        InboxMessageQuery::class,
        new FakeInboxMessageQuery([$otherRow], $this->app->make(DatabaseManager::class)),
    );

    $job = new ProcessFetchedInboxMessagesJob($this->fixtureUser->id);
    $job->handle(
        $this->app->make(DatabaseManager::class),
        $this->app->make(Clock::class),
        $this->app->make(Filesystem::class),
        $this->app->make(InboxMessageQuery::class),
        $this->app->make(EmlBlobStore::class),
        $this->app->make(RecordReceipt::class),
        $this->app->make(ReceiptSourceAdapter::class),
        $this->app->make(NormalizeStage::class),
        $this->app->make(RecordsTransactions::class),
    );

    $row = DB::table('inbox_messages')->where('user_id', $otherUser->id)->first();
    expect($row->status)->toBe('fetched');
});
