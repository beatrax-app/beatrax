<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\UploadLimits;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Modules\EmailScan\Public\Services\InboxMessageQuery;
use Modules\Import\Public\Actions\EnsureGooglePlayAccountAction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Receipts\Internal\Jobs\ProcessFetchedInboxMessagesJob;
use Modules\Receipts\Internal\ReceiptLedgerBridge;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Tests\Doubles\FakeInboxMessageQuery;
use Psr\Log\LoggerInterface;

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
        $this->app->make(ReceiptLedgerBridge::class),
        $this->app->make(LoggerInterface::class),
    );

    $rows = DB::table('inbox_messages')->where('user_id', $this->fixtureUser->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(3);
    expect($rows[0]->status)->toBe('parsed');
    expect($rows[0]->matcher_key)->toBe('paypal-receipt');
    expect($rows[1]->status)->toBe('skipped');
    expect($rows[2]->status)->toBe('unmatched');

    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(1);
});

// The .eml on disk is exactly the size the sender of that mail chose. Reading
// it whole to find out is the fatal; the fixture here is a receipt that parses
// into a transaction, so a run that produced one read bytes it should not have.
it('refuses a stored message past the ceiling and never hands its bytes to the parser', function (): void {
    Log::spy();

    DB::table('inboxes')->insert([
        'user_id' => $this->fixtureUser->id,
        'provider' => 'gmail',
        'email' => 'cardholder@gmail.test',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
    $inboxId = (int) DB::table('inboxes')->where('user_id', $this->fixtureUser->id)->value('id');

    $internalDate = new DateTimeImmutable('2026-05-17T09:42:13+02:00');
    $seed = $this->seedInboxRowAndBlob;
    $row = $seed(
        $this->fixtureUser->id,
        $inboxId,
        'rcpt-oversized',
        'service@paypal.com',
        'Je ontvangstbewijs',
        $internalDate,
        (string) file_get_contents(__DIR__.'/../fixtures/paypal/current-receipt.eml'),
    );

    // Grown past the ceiling by seeking rather than writing: the size the
    // reader has to trust is real, the disk cost of the fixture is not.
    /** @var EmlBlobStore $blobs */
    $blobs = app(EmlBlobStore::class);
    $blobPath = $blobs->pathFor($this->fixtureUser->id, $inboxId, $internalDate, 'rcpt-oversized');
    $handle = fopen($blobPath, 'r+b');
    fseek($handle, UploadLimits::MAX_MESSAGE_BYTES);
    fwrite($handle, "\n");
    fclose($handle);
    clearstatcache(true, $blobPath);
    expect(filesize($blobPath))->toBeGreaterThan(UploadLimits::MAX_MESSAGE_BYTES);

    $this->app->instance(
        InboxMessageQuery::class,
        new FakeInboxMessageQuery([$row], $this->app->make(DatabaseManager::class)),
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

    expect(DB::table('inbox_messages')->where('id', $row->id)->value('status'))->toBe('unmatched');
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
    expect(DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message): bool => str_contains($message, 'refused to read a stored message whole'));
});

it('does not touch rows whose userId mismatches the job target (cross-user defence)', function (): void {
    DB::table('inboxes')->insert([
        'user_id' => $this->fixtureUser->id,
        'provider' => 'gmail',
        'email' => 'cardholder@gmail.test',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
    $inboxId = (int) DB::table('inboxes')->where('user_id', $this->fixtureUser->id)->value('id');

    $otherUser = User::query()->create([
        'username' => 'other',
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
        $this->app->make(ReceiptLedgerBridge::class),
        $this->app->make(LoggerInterface::class),
    );

    $row = DB::table('inbox_messages')->where('user_id', $otherUser->id)->first();
    expect($row->status)->toBe('fetched');
});

it('marks a fetched row unmatched when its blob is missing (re-fetch, not phantom-orphan)', function (): void {
    DB::table('inboxes')->insert([
        'user_id' => $this->fixtureUser->id,
        'provider' => 'gmail',
        'email' => 'cardholder@gmail.test',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
    $inboxId = (int) DB::table('inboxes')->where('user_id', $this->fixtureUser->id)->value('id');

    // Insert the fetched row WITHOUT ever writing its .eml blob, so the
    // job takes the missing-blob branch.
    $internalDate = new DateTimeImmutable('2026-05-17T09:42:13+02:00');
    $now = $internalDate->format('Y-m-d H:i:s');
    DB::table('inbox_messages')->insert([
        'user_id' => $this->fixtureUser->id,
        'inbox_id' => $inboxId,
        'provider_message_id' => 'rcpt-missing-blob',
        'internal_date' => $now,
        'sender_email' => 'service@paypal.com',
        'sender_name' => null,
        'subject' => 'Receipt',
        'status' => 'fetched',
        'matcher_key' => null,
        'fetched_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $rowId = (int) DB::table('inbox_messages')
        ->where('user_id', $this->fixtureUser->id)
        ->where('provider_message_id', 'rcpt-missing-blob')
        ->value('id');

    $dto = new InboxMessageDto(
        id: $rowId,
        userId: $this->fixtureUser->id,
        inboxId: $inboxId,
        providerMessageId: 'rcpt-missing-blob',
        internalDate: $internalDate,
        senderEmail: 'service@paypal.com',
        senderName: null,
        subject: 'Receipt',
        status: 'fetched',
        fetchedAt: $internalDate,
    );

    $this->app->instance(
        InboxMessageQuery::class,
        new FakeInboxMessageQuery([$dto], $this->app->make(DatabaseManager::class)),
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

    expect(DB::table('inbox_messages')->where('id', $rowId)->value('status'))->toBe('unmatched');
});

it('lands a Google Play receipt in the ledger once its synthetic account exists', function (): void {
    DB::table('inboxes')->insert([
        'user_id' => $this->fixtureUser->id,
        'provider' => 'gmail',
        'email' => 'cardholder@gmail.test',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
    $inboxId = (int) DB::table('inboxes')->where('user_id', $this->fixtureUser->id)->value('id');

    // EnsureGooglePlayAccountAction is the ONLY thing in the app that can mint
    // this account; before it existed the matcher's synthetic IBAN resolved to
    // nothing on every path and the receipt could never reach the ledger.
    /** @var EnsureGooglePlayAccountAction $ensure */
    $ensure = app(EnsureGooglePlayAccountAction::class);
    expect($ensure($this->fixtureUser))->toBeTrue();

    $googleBytes = (string) file_get_contents(__DIR__.'/../fixtures/googleplay/current-receipt.eml');
    $seed = $this->seedInboxRowAndBlob;
    $row = $seed($this->fixtureUser->id, $inboxId, 'rcpt-gp-lands', 'googleplay-noreply@google.com', 'Your Google Play Order Receipt', new DateTimeImmutable('2026-05-17T09:30:00+00:00'), $googleBytes);

    $this->app->instance(
        InboxMessageQuery::class,
        new FakeInboxMessageQuery([$row], $this->app->make(DatabaseManager::class)),
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

    $account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN)
        ->firstOrFail();
    expect($account->kind)->toBe(AccountKind::GooglePlay->value);

    expect(DB::table('inbox_messages')->where('id', $row->id)->value('status'))->toBe('parsed');
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->where('account_id', $account->id)->count())->toBe(1);
    expect(Transaction::query()->where('account_id', $account->id)->value('source_ref'))->toBe('GPA.1234-5678-9012-34567');
});

it('records a parsed receipt but writes no transaction while the reader has not named the account', function (): void {
    DB::table('inboxes')->insert([
        'user_id' => $this->fixtureUser->id,
        'provider' => 'gmail',
        'email' => 'cardholder@gmail.test',
        'created_at' => '2026-05-17 00:00:00',
        'updated_at' => '2026-05-17 00:00:00',
    ]);
    $inboxId = (int) DB::table('inboxes')->where('user_id', $this->fixtureUser->id)->value('id');

    // Unnamed is a state the reader can leave: the preview wizard's Google Play
    // arm mints the account, after which the sibling test above lands the row.
    // The parse itself is still recorded so the naming prompt has something to
    // re-run over.
    $googleBytes = (string) file_get_contents(__DIR__.'/../fixtures/googleplay/current-receipt.eml');
    $seed = $this->seedInboxRowAndBlob;
    $row = $seed($this->fixtureUser->id, $inboxId, 'rcpt-noacct', 'googleplay-noreply@google.com', 'Your Google Play Order Receipt', new DateTimeImmutable('2026-05-17T09:30:00+00:00'), $googleBytes);

    $this->app->instance(
        InboxMessageQuery::class,
        new FakeInboxMessageQuery([$row], $this->app->make(DatabaseManager::class)),
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

    expect(DB::table('inbox_messages')->where('id', $row->id)->value('status'))->toBe('parsed');
    expect(DB::table('inbox_messages')->where('id', $row->id)->value('matcher_key'))->toBe('google-play-receipt');
    expect(Transaction::query()->where('user_id', $this->fixtureUser->id)->count())->toBe(0);
});
