<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Database\Seeders\IcsStatementSenderSeeder;
use Modules\EmailScan\Internal\Jobs\DetectIcsStatementReadyJob;
use Modules\EmailScan\Public\Events\IcsStatementReady;

/*
 * Req 14 (D-14/D-15) — the ICS "statement ready" metadata-only detector.
 *
 *  - A fixture inbox_messages row whose sender_email + subject match the
 *    tunable config pattern dispatches exactly one IcsStatementReady event.
 *  - A non-matching sender/subject dispatches none.
 *  - The detector's own source file never references EmlBlobStore — its
 *    entire input surface is the sender_email/subject columns, never the
 *    .eml body (Req 14, T-19-16-01).
 *  - IcsStatementSenderSeeder lands the missing 'icscards.nl' system
 *    known_sender row idempotently, without duplicating the pre-existing
 *    '@ics.nl' system row.
 */

function isrUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function isrSeedInbox(User $owner): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    return (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
        'email' => $owner->username.'@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function isrSeedMessage(
    User $owner,
    int $inboxId,
    string $senderEmail,
    ?string $subject,
    string $internalDate,
    string $status = 'fetched',
): int {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    static $i = 0;
    $i++;

    return (int) $db->connection()->table('inbox_messages')->insertGetId([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'provider_message_id' => 'isr-msg-'.$i,
        'internal_date' => $internalDate,
        'sender_email' => $senderEmail,
        'sender_name' => null,
        'subject' => $subject,
        'status' => $status,
        'fetched_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/** @return list<IcsStatementReady> */
function isrCaptureDispatched(User $user): array
{
    /** @var list<IcsStatementReady> $captured */
    $captured = [];
    app(Dispatcher::class)->listen(IcsStatementReady::class, function (IcsStatementReady $event) use (&$captured): void {
        $captured[] = $event;
    });

    $job = new DetectIcsStatementReadyJob($user->id);
    app()->call([$job, 'handle']);

    return $captured;
}

it('dispatches exactly one IcsStatementReady for a matching sender + subject row', function (): void {
    $user = isrUser('isr-match');
    $inboxId = isrSeedInbox($user);
    isrSeedMessage($user, $inboxId, 'noreply@icscards.nl', 'Uw ICS-afschrift is klaar', '2026-07-15 08:00:00');

    $events = isrCaptureDispatched($user);

    expect($events)->toHaveCount(1);
    expect($events[0]->userId)->toBe($user->id);
    expect($events[0]->internalDate->format('Y-m'))->toBe('2026-07');
});

it('dispatches none for a non-matching sender domain', function (): void {
    $user = isrUser('isr-wrong-sender');
    $inboxId = isrSeedInbox($user);
    isrSeedMessage($user, $inboxId, 'noreply@ics.nl.attacker.example', 'Your statement is ready', '2026-07-15 08:00:00');

    expect(isrCaptureDispatched($user))->toBe([]);
});

it('dispatches none for a matching sender but non-matching subject', function (): void {
    $user = isrUser('isr-wrong-subject');
    $inboxId = isrSeedInbox($user);
    isrSeedMessage($user, $inboxId, 'noreply@icscards.nl', 'Your card was charged EUR 12.34', '2026-07-15 08:00:00');

    expect(isrCaptureDispatched($user))->toBe([]);
});

it('is status-agnostic — a co-matched row already flipped to unmatched by Receipts still dispatches', function (): void {
    $user = isrUser('isr-status-agnostic');
    $inboxId = isrSeedInbox($user);
    isrSeedMessage($user, $inboxId, 'noreply@icscards.nl', 'Your statement is ready', '2026-07-15 08:00:00', status: 'unmatched');

    expect(isrCaptureDispatched($user))->toHaveCount(1);
});

it('never imports EmlBlobStore/RecordReceipt — the detector reads only sender_email/subject columns (Req 14)', function (): void {
    $source = (string) file_get_contents(
        base_path('Modules/EmailScan/Internal/Jobs/DetectIcsStatementReadyJob.php'),
    );
    // Strip comments/docblocks before asserting: the class docblock
    // legitimately DISCUSSES EmlBlobStore (to explain what it deliberately
    // does NOT do) — the structural guarantee this test proves is that no
    // executable line imports or instantiates it.
    $codeOnly = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    expect($codeOnly)->not->toContain('EmlBlobStore');
    expect($codeOnly)->not->toContain('RecordReceipt');
});

it('runs idempotently — re-running the seeder never duplicates the system @ics.nl or @icscards.nl rows', function (): void {
    // The companion data migration already ran IcsStatementSenderSeeder
    // once during this test's own RefreshDatabase migrate pass (mirrors
    // Modules/EmailScan/tests/Integration/MigrationsTest.php's updated
    // "4 system rows" assertion) — re-running it here must be a no-op,
    // not a duplicate insert.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection()->table('known_senders')->whereNull('user_id')->where('email_pattern', '@ics.nl')->count())->toBe(1);
    expect($db->connection()->table('known_senders')->whereNull('user_id')->where('email_pattern', '@icscards.nl')->count())->toBe(1);

    app(IcsStatementSenderSeeder::class)->run();
    app(IcsStatementSenderSeeder::class)->run();

    expect($db->connection()->table('known_senders')->whereNull('user_id')->where('email_pattern', '@icscards.nl')->count())->toBe(1);
    expect($db->connection()->table('known_senders')->whereNull('user_id')->where('email_pattern', '@ics.nl')->count())->toBe(1);
});
