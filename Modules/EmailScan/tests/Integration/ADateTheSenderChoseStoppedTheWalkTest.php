<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\EmailScan\Internal\InboxScanStateMachine;
use Modules\EmailScan\Internal\Jobs\DiscoveryScanJob;
use Modules\EmailScan\Internal\Jobs\InboxScanContext;
use Modules\EmailScan\Internal\MimeHeaderParser;
use Modules\EmailScan\Public\Services\EmlBlobStore;
use Psr\Log\NullLogger;

uses(RefreshDatabase::class);

// The `Date:` header is the sender's, and MimeHeaderParser prefers it over the
// provider's own stamp. `inbox_messages.internal_date` is a DATETIME read back
// with CarbonImmutable::parse, so Instant::appLocal refuses anything that is
// not a zero-padded `Y-m-d H:i:s` — and a date the app zone pushes past the
// year 9999 is exactly that. InboxScanContext says what is supposed to happen
// to a message this device cannot hold: it is skipped, never let out of the
// walk, because a refusal that escapes leaves the cursor where it was and
// every later tick walks back into the same message.
const DATE_STOPPED_WALK_ID = 'ics-far-future';

function dateStoppedWalkEml(string $date): string
{
    return implode("\r\n", [
        'From: "ICS Cards" <noreply@ics.nl>',
        'To: "Synthetic User" <local-user@example.test>',
        'Subject: Je nieuwe maandafschrift staat klaar',
        'Date: '.$date,
        'Message-ID: <synthetic-far-future@ics.nl>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Je afschrift staat klaar.',
        '',
    ]);
}

function dateStoppedWalkContext(User $owner, int $inboxId): InboxScanContext
{
    return new InboxScanContext(
        inboxId: $inboxId,
        clock: app(Clock::class),
        sm: app(InboxScanStateMachine::class),
        connection: app(DatabaseManager::class)->connection(),
        blobStore: app(EmlBlobStore::class),
        mime: app(MimeHeaderParser::class),
        userId: $owner->id,
        logger: new NullLogger,
    );
}

beforeEach(function (): void {
    $this->owner = User::query()->create([
        'username' => 'date-stopped-walk',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $this->inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $this->owner->id,
        'provider' => 'gmail',
        'email' => 'date-stopped-walk@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->inboxRoot = storage_path('app/inbox');
});

afterEach(function (): void {
    if (is_dir($this->inboxRoot)) {
        app(Filesystem::class)->deleteDirectory($this->inboxRoot);
    }
});

// `Mon` is not the weekday of 31 December 9999, and a parser that honours the
// day name rolls forward to the next one — past the year, in UTC, before any
// zone offset is applied. A sender picks both halves.
it('stores a message whose Date header the storage frame cannot hold', function (): void {
    $context = dateStoppedWalkContext($this->owner, $this->inboxId);
    $provider = new DateTimeImmutable('2026-05-11 09:00:00', new DateTimeZone('UTC'));

    $context->storeFetchedMessage(
        DATE_STOPPED_WALK_ID,
        dateStoppedWalkEml('Mon, 31 Dec 9999 23:59:59 +0000'),
        $provider,
    );

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $stored = $db->connection()->table('inbox_messages')
        ->where('inbox_id', $this->inboxId)
        ->where('provider_message_id', DATE_STOPPED_WALK_ID)
        ->value('internal_date');

    expect($stored)->not->toBeNull(
        'the message was not stored at all: a Date header the sender chose let a refusal out of the walk, '
        .'and a refusal out of the walk leaves the cursor on this message for every later tick.',
    );
    expect((string) $stored)->toStartWith('2026-05-11');
});

// The control. Falling back for every message would satisfy the assertion
// above while throwing away the one stamp the reader's own mail carries.
it('still dates a message by its own header when the frame can hold it', function (): void {
    $context = dateStoppedWalkContext($this->owner, $this->inboxId);
    $provider = new DateTimeImmutable('2026-05-11 09:00:00', new DateTimeZone('UTC'));

    $context->storeFetchedMessage(
        'ics-ordinary',
        dateStoppedWalkEml('Mon, 11 May 2026 07:31:01 +0000'),
        $provider,
    );

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $stored = $db->connection()->table('inbox_messages')
        ->where('inbox_id', $this->inboxId)
        ->where('provider_message_id', 'ics-ordinary')
        ->value('internal_date');

    expect((string) $stored)->toStartWith('2026-05-11 07:31:01');
});

// The same shape one function over: a discovery pass reads the provider's own
// stamp, and `last_seen_at` is the same DATETIME frame. safeParseDate() is
// named for answering rather than throwing, and a stamp it parsed and could
// not store threw at the write instead.
it('keeps a discovery stamp inside the frame the column stores', function (): void {
    $job = new DiscoveryScanJob($this->owner->id);
    $parse = new ReflectionMethod($job, 'safeParseDate');
    $clock = app(Clock::class);
    $zone = date_default_timezone_get();

    // app.timezone is the storage frame, and the last hours of 9999 land in
    // the year 10000 in every zone east of UTC. A checkout runs in UTC, where
    // the same stamp is storable and this rule sees nothing.
    date_default_timezone_set('Europe/Amsterdam');

    try {
        /** @var DateTimeImmutable $far */
        $far = $parse->invoke($job, '9999-12-31T23:59:59+00:00', $clock);
        /** @var DateTimeImmutable $ordinary */
        $ordinary = $parse->invoke($job, '2026-05-11T07:31:01+00:00', $clock);

        expect(Instant::storesAsAppLocal($far))->toBeTrue(
            'a stamp the column cannot hold came back out of safeParseDate, and Instant::appLocal throws on '
            .'it at the write — aborting the pass rather than the candidate.',
        );

        // The control: answering with the clock for everything would satisfy
        // the assertion above and throw away every real last-seen stamp.
        expect(Instant::appLocal($ordinary))->toBe('2026-05-11 09:31:01');
    } finally {
        date_default_timezone_set($zone);
    }
});
