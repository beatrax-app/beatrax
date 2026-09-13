<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;
use Modules\EmailScan\Public\Actions\PromoteDiscoveredSender;
use Modules\EmailScan\Public\Dto\KnownSenderDto;
use Modules\EmailScan\Public\Services\KnownSenderQuery;

uses(RefreshDatabase::class);

// `known_senders.label` holds two different things, told apart today by how the
// value happens to START: a seeded row carries a copy spec and resolves in the
// reader's language, while a promoted row carries the From display name and
// comes back as it was sent. A display name is not a sentence a person writes
// — it is a string an unknown sender chooses, and the row it lands in travels
// to every paired device.
const SENDER_SPOKEN_KEY = 'email-scan::inboxes.known_sender.ics_statements';

function aSenderSpokenUser(): User
{
    return User::query()->create([
        'username' => 'sender-spoken',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function aSenderSpokenPromoted(User $owner, string $displayName): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
        'email' => 'sender-spoken@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $discoveredId = (int) $db->connection()->table('discovered_senders')->insertGetId([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'sender_email' => 'billing@statements.example',
        'sender_name' => $displayName,
        'occurrence_count' => 4,
        'last_seen_at' => $now,
        'state' => 'candidate',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    /** @var PromoteDiscoveredSender $promote */
    $promote = app(PromoteDiscoveredSender::class);
    ($promote)($discoveredId, $owner);
}

/** @return list<KnownSenderDto> */
function aSenderSpokenReadBack(User $owner): array
{
    /** @var KnownSenderQuery $query */
    $query = app(KnownSenderQuery::class);

    return $query->all($owner);
}

it('does not speak a sender display name as one of the app own lines', function (): void {
    $owner = aSenderSpokenUser();

    // The bytes the seeder writes for a line the app owns. A From header can
    // carry them; nothing about the envelope is secret, and nothing signs it.
    $forged = StoredCopy::of(CopyLine::of(SENDER_SPOKEN_KEY));

    aSenderSpokenPromoted($owner, $forged);

    App::setLocale('nl');
    $promoted = array_values(array_filter(
        aSenderSpokenReadBack($owner),
        static fn (KnownSenderDto $s): bool => $s->emailPattern === 'billing@statements.example',
    ));

    expect($promoted)->toHaveCount(1);
    expect($promoted[0]->label)->toBe(
        $forged,
        'the sender chose the sentence: the app read its own copy spec out of a From display name and '
        .'rendered a shipped line in the reader language, on this device and on every paired one.',
    );
});

// The control. Reading every label verbatim would satisfy the assertion above
// while leaving the three seeded senders printing raw JSON at the reader.
it('still resolves a seeded sender line in the reader language', function (): void {
    $owner = aSenderSpokenUser();

    App::setLocale('nl');
    $seeded = array_values(array_filter(
        aSenderSpokenReadBack($owner),
        static fn (KnownSenderDto $s): bool => $s->emailPattern === '@ics.nl',
    ));

    expect($seeded)->toHaveCount(1);
    expect($seeded[0]->label)->not->toStartWith('{"@copy":');
});
