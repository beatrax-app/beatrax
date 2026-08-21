<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Actions\DismissNotification;
use Modules\Notifications\Public\Actions\MarkNotificationRead;
use Modules\Notifications\Public\Actions\UndoDismissNotification;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Modules\Sync\Public\Events\NotificationMutated;

uses(RefreshDatabase::class);

function writerUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    $this->frozenNow = CarbonImmutable::parse('2026-07-18 09:00:00');
    $clock = $this->createStub(Clock::class);
    $clock->method('now')->willReturn($this->frozenNow);
    $this->app->instance(Clock::class, $clock);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('yields exactly one row and one deliverable+mutated event when writing the same tuple twice', function (): void {
    Event::fake([NotificationDeliverable::class, NotificationMutated::class]);

    $user = writerUser('writer-dedup');
    /** @var NotificationWriter $writer */
    $writer = $this->app->make(NotificationWriter::class);

    $id1 = $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        subjectKey: 'import-batch-1',
        occurrence: '2026-07-18',
        title: 'Import finished',
        body: '37 transactions imported.',
        deepLinkRoute: '/imports/new',
    ));

    $id2 = $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        subjectKey: 'import-batch-1',
        occurrence: '2026-07-18',
        title: 'Import finished',
        body: '37 transactions imported.',
        deepLinkRoute: '/imports/new',
    ));

    expect($id1)->toBe($id2);
    expect($this->db->connection()->table('notifications')->count())->toBe(1);

    Event::assertDispatchedTimes(NotificationDeliverable::class, 1);
    Event::assertDispatchedTimes(NotificationMutated::class, 1);
});

it('yields a second row for a different occurrence', function (): void {
    $user = writerUser('writer-different-occurrence');
    /** @var NotificationWriter $writer */
    $writer = $this->app->make(NotificationWriter::class);

    $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER,
        subjectKey: 'series-1',
        occurrence: '2026-07-01',
        title: 'Payment due Friday',
        body: 'Netflix — 12.99 EUR.',
    ));

    $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_PAYMENT_REMINDER,
        subjectKey: 'series-1',
        occurrence: '2026-08-01',
        title: 'Payment due Friday',
        body: 'Netflix — 12.99 EUR.',
    ));

    expect($this->db->connection()->table('notifications')->where('user_id', $user->id)->count())->toBe(2);
});

it('returns an id equal to DeterministicKeyDeriver::derive() for the same inputs', function (): void {
    $user = writerUser('writer-id-parity');
    /** @var NotificationWriter $writer */
    $writer = $this->app->make(NotificationWriter::class);
    /** @var DeterministicKeyDeriver $deriver */
    $deriver = $this->app->make(DeterministicKeyDeriver::class);

    $id = $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE,
        subjectKey: 'envelope-42',
        occurrence: '2026-07',
        title: 'Budget nearly spent',
        body: 'Groceries is at 92%.',
    ));

    $expected = $deriver->derive($user->id, DeterministicKeyDeriver::TRIGGER_BUDGET_NUDGE, 'envelope-42', '2026-07');

    expect($id)->toBe($expected);
});

it('dispatches no events at all on a duplicate write', function (): void {
    $user = writerUser('writer-noop-duplicate');
    /** @var NotificationWriter $writer */
    $writer = $this->app->make(NotificationWriter::class);

    $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
        subjectKey: 'series-9',
        occurrence: '2026-07-18',
        title: 'A recurring charge changed',
        body: 'Spotify moved up by 2.00 EUR.',
    ));

    Event::fake([NotificationDeliverable::class, NotificationMutated::class]);

    $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_DRIFT_CHANGED,
        subjectKey: 'series-9',
        occurrence: '2026-07-18',
        title: 'A recurring charge changed',
        body: 'Spotify moved up by 2.00 EUR.',
    ));

    Event::assertNotDispatched(NotificationDeliverable::class);
    Event::assertNotDispatched(NotificationMutated::class);
});

it('dispatches exactly one NotificationMutated with mutationType create on write', function (): void {
    Event::fake([NotificationMutated::class]);

    $user = writerUser('writer-create-event');
    /** @var NotificationWriter $writer */
    $writer = $this->app->make(NotificationWriter::class);

    $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_SAVINGS_PROMPT,
        subjectKey: 'counterparty-7',
        occurrence: '2026-07-18',
        title: 'A cheaper plan exists',
        body: 'Netflix may have a cheaper plan.',
    ));

    Event::assertDispatchedTimes(NotificationMutated::class, 1);
    Event::assertDispatched(NotificationMutated::class, function (NotificationMutated $event) use ($user): bool {
        return $event->userId === $user->id && $event->mutationType === 'create';
    });
});

it('leaves the first read_at timestamp intact when MarkNotificationRead runs twice (one-way latch)', function (): void {
    $user = writerUser('mark-read-latch');
    /** @var NotificationWriter $writer */
    $writer = $this->app->make(NotificationWriter::class);
    $id = $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_FORECAST_SHORTFALL,
        subjectKey: 'forecast-period-2026-08',
        occurrence: '2026-08',
        title: 'Cash-flow shortfall ahead',
        body: 'Your projected balance dips below zero.',
    ));

    /** @var MarkNotificationRead $markRead */
    $markRead = $this->app->make(MarkNotificationRead::class);
    $markRead($id, $user);

    $firstReadAt = $this->db->connection()->table('notifications')->where('id', $id)->value('read_at');
    expect($firstReadAt)->not->toBeNull();

    // The clock moves so a re-write would produce a visibly different value.
    $laterClock = $this->createStub(Clock::class);
    $laterClock->method('now')->willReturn(CarbonImmutable::parse('2026-07-19 10:00:00'));
    $this->app->instance(Clock::class, $laterClock);
    $this->app->forgetInstance(MarkNotificationRead::class);
    /** @var MarkNotificationRead $markReadAgain */
    $markReadAgain = $this->app->make(MarkNotificationRead::class);
    $markReadAgain($id, $user);

    $secondReadAt = $this->db->connection()->table('notifications')->where('id', $id)->value('read_at');
    expect($secondReadAt)->toBe($firstReadAt);
});

it('round-trips dismissed_at to null via Dismiss then UndoDismiss', function (): void {
    $user = writerUser('dismiss-roundtrip');
    /** @var NotificationWriter $writer */
    $writer = $this->app->make(NotificationWriter::class);
    $id = $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_RECEIPTS_FOUND,
        subjectKey: 'receipt-batch-3',
        occurrence: '2026-07-18',
        title: 'New receipts found',
        body: '1 receipt matched from your email.',
    ));

    /** @var DismissNotification $dismiss */
    $dismiss = $this->app->make(DismissNotification::class);
    $dismiss($id, $user);

    expect($this->db->connection()->table('notifications')->where('id', $id)->value('dismissed_at'))->not->toBeNull();

    /** @var UndoDismissNotification $undo */
    $undo = $this->app->make(UndoDismissNotification::class);
    $undo($id, $user);

    expect($this->db->connection()->table('notifications')->where('id', $id)->value('dismissed_at'))->toBeNull();
});

it('never changes state via any of the three lifecycle actions', function (): void {
    $user = writerUser('state-untouched');
    /** @var NotificationWriter $writer */
    $writer = $this->app->make(NotificationWriter::class);
    $id = $writer->write(new NotificationDraft(
        userId: $user->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_POSITION_DIGEST,
        subjectKey: 'digest-2026-w29',
        occurrence: '2026-W29',
        title: 'Your weekly position',
        body: 'Your projected balance next week is 1,204.50 EUR.',
    ));

    /** @var MarkNotificationRead $markRead */
    $markRead = $this->app->make(MarkNotificationRead::class);
    $markRead($id, $user);

    /** @var DismissNotification $dismiss */
    $dismiss = $this->app->make(DismissNotification::class);
    $dismiss($id, $user);

    /** @var UndoDismissNotification $undo */
    $undo = $this->app->make(UndoDismissNotification::class);
    $undo($id, $user);

    expect($this->db->connection()->table('notifications')->where('id', $id)->value('state'))->toBe('open');
});

it('does not let a cross-user MarkNotificationRead mutate the row', function (): void {
    $owner = writerUser('cross-user-owner');
    $attacker = writerUser('cross-user-attacker');
    /** @var NotificationWriter $writer */
    $writer = $this->app->make(NotificationWriter::class);
    $id = $writer->write(new NotificationDraft(
        userId: $owner->id,
        triggerType: DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        subjectKey: 'cross-user-batch',
        occurrence: '2026-07-18',
        title: 'Import finished',
        body: '5 transactions imported.',
    ));

    /** @var MarkNotificationRead $markRead */
    $markRead = $this->app->make(MarkNotificationRead::class);
    $markRead($id, $attacker);

    expect($this->db->connection()->table('notifications')->where('id', $id)->value('read_at'))->toBeNull();
});
