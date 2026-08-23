<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Internal\Listeners\RaiseReconsentAlertOnTokenFailure;
use Modules\EmailScan\Public\Enums\DiscoveredSenderState;
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Events\InboxTokenFailed;
use Modules\EmailScan\Public\Services\DiscoveredSenderQuery;
use Modules\EmailScan\Public\Services\InboxQuery;

// The candidates panel and the review badge select on discovered_senders.state,
// which DiscoveredSenderState owns and a CHECK trigger enforces. As a bare
// string they fail silently: the query stays valid, nothing matches, and a badge
// at zero looks like an inbox with nothing left to review.

function dsvSeedInbox(User $owner): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => MailProvider::Gmail->value,
        'email' => $owner->username.'@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => InboxScanStatus::Idle->value,
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

function dsvSeedSender(User $owner, int $inboxId, DiscoveredSenderState $state, string $sender): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $db->connection()->table('discovered_senders')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'sender_email' => $sender,
        'sender_name' => null,
        'occurrence_count' => 5,
        'last_seen_at' => CarbonImmutable::now()->subDays(3)->toDateTimeString(),
        'state' => $state->value,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

beforeEach(function (): void {
    $this->reader = User::query()->create([
        'username' => 'sender-vocabulary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->reader);
    $this->inboxId = dsvSeedInbox($this->reader);
});

it('offers only the senders stored under the state the owning enum calls a candidate', function (): void {
    dsvSeedSender($this->reader, $this->inboxId, DiscoveredSenderState::Candidate, 'candidate@example.com');
    dsvSeedSender($this->reader, $this->inboxId, DiscoveredSenderState::Added, 'added@example.com');
    dsvSeedSender($this->reader, $this->inboxId, DiscoveredSenderState::Dismissed, 'dismissed@example.com');

    /** @var DiscoveredSenderQuery $query */
    $query = app(DiscoveredSenderQuery::class);
    $rows = $query->candidatesForUser($this->reader);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->senderEmail)->toBe('candidate@example.com');
});

it('counts only the candidates toward the review badge', function (): void {
    dsvSeedSender($this->reader, $this->inboxId, DiscoveredSenderState::Candidate, 'one@example.com');
    dsvSeedSender($this->reader, $this->inboxId, DiscoveredSenderState::Candidate, 'two@example.com');
    dsvSeedSender($this->reader, $this->inboxId, DiscoveredSenderState::Dismissed, 'three@example.com');

    /** @var InboxQuery $query */
    $query = app(InboxQuery::class);

    expect($query->reviewBadgeCount($this->reader))->toBe(2);
});

// The table carries a CHECK trigger naming its own vocabulary. If a case ever
// stops matching, the insert aborts here rather than in a panel gone quietly
// empty.
it('stores every case the discovered_senders state trigger accepts', function (): void {
    foreach (DiscoveredSenderState::cases() as $index => $state) {
        dsvSeedSender($this->reader, $this->inboxId, $state, 'vocab-'.$index.'@example.com');
    }

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection()->table('discovered_senders')->where('user_id', $this->reader->id)->count())
        ->toBe(count(DiscoveredSenderState::cases()));
});

// The reconsent alert kind is one value in an open registry, so no CHECK
// trigger guards it: the raiser's constant is the only thing keeping the
// acknowledging reader on the same spelling.
it('acknowledges the alert its own raiser wrote, end to end', function (): void {
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new InboxTokenFailed($this->inboxId, $this->reader->id, MailProvider::Gmail->value));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection()->table('system_alerts')
        ->where('user_id', $this->reader->id)
        ->where('kind', RaiseReconsentAlertOnTokenFailure::ALERT_KIND)
        ->whereNull('acknowledged_at')
        ->count())->toBe(1);

    Livewire::test(InboxesPage::class)->call('acknowledgeReconnect', $this->inboxId);

    expect($db->connection()->table('system_alerts')
        ->where('user_id', $this->reader->id)
        ->whereNull('acknowledged_at')
        ->count())->toBe(0);
});
