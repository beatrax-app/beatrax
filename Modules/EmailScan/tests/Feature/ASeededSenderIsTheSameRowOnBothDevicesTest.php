<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\EmailScan\Database\Seeders\Demo\DemoEmailScanSeeder;
use Modules\EmailScan\Public\Actions\PromoteDiscoveredSender;
use Modules\EmailScan\Public\Enums\DiscoveredSenderState;
use Modules\EmailScan\Public\Enums\MailProvider;

uses(RefreshDatabase::class);

// known_senders folds (user_id, email_pattern) into its primary key so two
// devices land on one number without exchanging a message, and the migration
// that installed that dropped AUTOINCREMENT to make room for it. SQLite still
// answers an insert naming no id with max(id) + 1, so a writer that leaves the
// id out takes the number just above the largest derived one — a number that
// says nothing except what this device happened to be holding at the time.
//
// The second device is the same database again with the reader's own senders
// cleared, which is what the pair really is: two installs running the same code
// over the same reader, apart.

/**
 * @return array<string, int> email_pattern => id, for this reader's own senders
 */
function ksdOwnSenders(DatabaseManager $db, int $userId): array
{
    $senders = [];

    foreach ($db->connection()->table('known_senders')->where('user_id', $userId)->get() as $row) {
        $senders[(string) $row->email_pattern] = (int) $row->id;
    }

    return $senders;
}

function ksdDerivedId(int $userId, string $emailPattern): int
{
    return DerivedRowId::for('known_senders', [
        'user_id' => $userId,
        'email_pattern' => $emailPattern,
    ]);
}

function ksdLoadSampleData(User $user): void
{
    app(DemoEmailScanSeeder::class)->run(['demo-1' => $user]);
}

function ksdForgetOwnSenders(DatabaseManager $db, int $userId): void
{
    $db->connection()->table('known_senders')->where('user_id', $userId)->delete();
}

// The Inbox screen's own path: a candidate the reader promotes gets a derived
// id, so this device holds a sender the other one reached a different way.
function ksdPromoteFromTheInbox(DatabaseManager $db, User $user, string $senderEmail): void
{
    $connection = $db->connection();
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = $connection->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => MailProvider::Gmail->value,
        'email' => 'reader+promoted@beatrax.local',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $discoveredId = $connection->table('discovered_senders')->insertGetId([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'sender_email' => $senderEmail,
        'sender_name' => 'Spotify',
        'last_seen_at' => $now,
        'state' => DiscoveredSenderState::Candidate->value,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    app(PromoteDiscoveredSender::class)($discoveredId, $user);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-11 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'demo-1',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('gives a sender the sample data adds the id both devices compute for it', function (): void {
    $userId = (int) $this->user->id;

    ksdLoadSampleData($this->user);

    $minted = [];
    foreach (ksdOwnSenders($this->db, $userId) as $pattern => $id) {
        if ($id !== ksdDerivedId($userId, $pattern)) {
            $minted[] = $pattern.' holds '.$id.', and both devices derive '.ksdDerivedId($userId, $pattern);
        }
    }

    expect($minted)->toBe([], implode("\n", [
        'The sample data left these senders on an id taken from the sequence. The number',
        'is max(id) + 1 over whatever that device happened to hold, so the peer computing',
        'the same sender arrives at a different one:',
        ...$minted,
    ]));
});

it('does not hand one id to two different senders', function (): void {
    $userId = (int) $this->user->id;

    // One device: the reader loaded the sample data and did nothing else.
    ksdLoadSampleData($this->user);
    $here = ksdOwnSenders($this->db, $userId);

    // The other: the reader had already promoted Spotify off the Inbox screen,
    // so the sample data finds that one present and adds only the second.
    ksdForgetOwnSenders($this->db, $userId);
    ksdPromoteFromTheInbox($this->db, $this->user, 'subscriptions@spotify.com');
    ksdLoadSampleData($this->user);
    $there = ksdOwnSenders($this->db, $userId);

    $clashes = [];
    foreach ($here as $pattern => $id) {
        $peerPattern = array_search($id, $there, true);

        if (is_string($peerPattern) && $peerPattern !== $pattern) {
            $clashes[] = $id.' is '.$pattern.' on one device and '.$peerPattern.' on the other';
        }
    }

    expect($clashes)->toBe([], implode("\n", [
        'One primary key names two different senders across the pair. The arriving create',
        'is refused by the key it collides with and quarantined, and the sender it carried',
        'never reaches the other device:',
        ...$clashes,
    ]));
});
