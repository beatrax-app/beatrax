<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Internal\Http\Livewire\BackfillWindowModal;
use Modules\EmailScan\Internal\Jobs\BackfillInboxJob;

function bwmUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function bwmSeedInbox(User $owner, int $window = 3): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
        'email' => $owner->username.'@example.com',
        'backfill_window_months' => $window,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

it('open() sets the inboxId and the currentWindow into the slider value', function (): void {
    $user = bwmUser('open@example.com');
    $inboxId = bwmSeedInbox($user, window: 6);
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', $inboxId, 6)
        ->assertSet('inboxId', $inboxId)
        ->assertSet('months', 6);
});

it('open() defaults to 3 months when currentWindow is not supplied', function (): void {
    $user = bwmUser('default@example.com');
    $inboxId = bwmSeedInbox($user);
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', $inboxId)
        ->assertSet('inboxId', $inboxId)
        ->assertSet('months', 3);
});

it('happy submit: persists window + dispatches BackfillInboxJob + emits modal-hide', function (): void {
    Bus::fake();
    $user = bwmUser('happy@example.com');
    $inboxId = bwmSeedInbox($user);
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', $inboxId, 3)
        ->set('months', 4)
        ->call('submit')
        ->assertDispatched('modal-close');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('inboxes')->where('id', $inboxId)->first(['backfill_window_months']);
    expect($row)->not->toBeNull();
    expect((int) $row->backfill_window_months)->toBe(4);

    Bus::assertDispatched(BackfillInboxJob::class, function (BackfillInboxJob $job) use ($inboxId): bool {
        return $job->inboxId === $inboxId && $job->windowMonths === 4;
    });
});

it('defensive clamp: months=999 is clamped to 12 before dispatch + persist', function (): void {
    Bus::fake();
    $user = bwmUser('clamp@example.com');
    $inboxId = bwmSeedInbox($user);
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', $inboxId, 3)
        ->set('months', 999)
        ->call('submit');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('inboxes')->where('id', $inboxId)->first(['backfill_window_months']);
    expect($row)->not->toBeNull();
    expect((int) $row->backfill_window_months)->toBe(12);

    Bus::assertDispatched(BackfillInboxJob::class, function (BackfillInboxJob $job): bool {
        return $job->windowMonths === 12;
    });
});

it('defensive clamp: months=0 is clamped up to 1 before dispatch + persist', function (): void {
    Bus::fake();
    $user = bwmUser('clamp-min@example.com');
    $inboxId = bwmSeedInbox($user);
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', $inboxId, 3)
        ->set('months', 0)
        ->call('submit');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('inboxes')->where('id', $inboxId)->first(['backfill_window_months']);
    expect((int) $row->backfill_window_months)->toBe(1);

    Bus::assertDispatched(BackfillInboxJob::class, function (BackfillInboxJob $job): bool {
        return $job->windowMonths === 1;
    });
});

it('cross-user refusal: submit against another user\'s inbox writes nothing and answers in the modal', function (): void {
    Bus::fake();
    $userA = bwmUser('cross-a@example.com');
    $userB = bwmUser('cross-b@example.com');
    $inboxA = bwmSeedInbox($userA);

    $this->actingAs($userB);

    Livewire::test(BackfillWindowModal::class)
        ->call('open', $inboxA, 3)
        ->set('months', 3)
        ->call('submit')
        ->assertStatus(200)
        ->assertSet('errorMessage', Lang::get('core::errors.no_longer_here'));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $row = $db->connection()->table('inboxes')->where('id', $inboxA)->first(['backfill_window_months']);
    expect((int) $row->backfill_window_months)->toBe(3);

    Bus::assertNotDispatched(BackfillInboxJob::class);
});

it('submit with no inboxId set answers in the modal', function (): void {
    $user = bwmUser('no-id@example.com');
    $this->actingAs($user);

    Livewire::test(BackfillWindowModal::class)
        ->call('submit')
        ->assertStatus(200)
        ->assertSet('errorMessage', Lang::get('core::errors.no_longer_here'));
});
